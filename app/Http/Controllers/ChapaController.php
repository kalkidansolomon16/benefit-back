<?php

namespace App\Http\Controllers;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\Company;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChapaController extends Controller
{
    private string $secretKey;
    private string $baseUrl;
    private string $returnUrl;
    private string $webhookUrl;
    private string $webhookSecret;

    public function __construct()
    {
        $this->secretKey     = config('services.chapa.secret_key');
        $this->baseUrl       = config('services.chapa.base_url');
        $this->returnUrl     = config('services.chapa.return_url');
        $this->webhookUrl    = config('services.chapa.webhook_url');
        // Chapa signs webhook payloads with a dedicated "Webhook Secret" (set in the
        // dashboard's Webhooks tab) — distinct from the API secret key used for
        // Authorization headers. Falls back to the secret key if no dedicated
        // webhook secret has been configured yet.
        $this->webhookSecret = config('services.chapa.webhook_secret') ?: $this->secretKey;
    }

    /* ── Initialize Chapa transaction ─────────────────────────── */

    public function initialize(BillingInvoice $billingInvoice): JsonResponse
    {
        $company = $this->getMyCompany();

        if ($billingInvoice->company_id !== $company->id || !$billingInvoice->isAccessibleBy(auth()->user())) {
            abort(403, 'This invoice belongs to another branch.');
        }

        if (!in_array($billingInvoice->status, ['sent', 'overdue'])) {
            return response()->json(['message' => 'This invoice is not awaiting payment.'], 422);
        }

        // Block if there is already a verified or pending Chapa payment
        $existing = $billingInvoice->payments()
            ->where('payment_channel', 'chapa')
            ->whereIn('status', ['verified', 'pending'])
            ->first();

        if ($existing) {
            return response()->json(['message' => 'A Chapa payment is already processing or completed for this invoice.'], 422);
        }

        // Generate unique transaction reference
        $txRef = 'FA-' . $billingInvoice->invoice_number . '-' . Str::upper(Str::random(6));

        $hrUser = auth()->user();

        // Build return URL with tx_ref so the frontend can verify
        $returnUrl = $this->returnUrl . '?tx_ref=' . $txRef;

        $payload = [
            'amount'      => number_format((float) $billingInvoice->total_amount, 2, '.', ''),
            'currency'    => 'ETB',
            'email'       => $hrUser->email,
            'first_name'  => explode(' ', $hrUser->name)[0],
            'last_name'   => explode(' ', $hrUser->name, 2)[1] ?? $company->name,
            'tx_ref'      => $txRef,
            'return_url'  => $returnUrl,
            'callback_url' => $this->webhookUrl,
            'customization' => [
                'title'       => 'FitAccess Pay',
                'description' => 'Invoice ' . preg_replace('/[^a-zA-Z0-9 _\-.]/', '', $billingInvoice->invoice_number) . ' ' . preg_replace('/[^a-zA-Z0-9 _\-.]/', '', $billingInvoice->billing_period),
            ],
        ];

        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl . '/transaction/initialize', $payload);

        if (!$response->successful()) {
            $error = $response->json('message') ?? 'Chapa initialization failed.';
            return response()->json(['message' => $error], 502);
        }

        $data = $response->json('data');

        // Store a pending Chapa payment record so we can track it
        BillingPayment::create([
            'billing_invoice_id' => $billingInvoice->id,
            'company_id'         => $company->id,
            'amount'             => $billingInvoice->total_amount,
            'payment_channel'    => 'chapa',
            'chapa_tx_ref'       => $txRef,
            'status'             => 'pending',
            'submitted_at'       => now(),
        ]);

        return response()->json([
            'checkout_url' => $data['checkout_url'],
            'tx_ref'       => $txRef,
        ]);
    }

    /* ── Verify Chapa transaction (called from frontend after redirect) ── */

    public function verify(Request $request): JsonResponse
    {
        $request->validate(['tx_ref' => 'required|string']);

        $payment = BillingPayment::where('chapa_tx_ref', $request->tx_ref)
            ->where('payment_channel', 'chapa')
            ->first();

        if (!$payment) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        }

        $result = $this->confirmPayment($payment);

        return response()->json($result['body'], $result['code']);
    }

    /* ── Webhook (server-to-server — called directly by Chapa) ──────
     * Registered publicly (no auth middleware) since Chapa itself must
     * reach it. Confirms the payment even if the customer's browser
     * never makes it back to the frontend return page. Chapa signs the
     * request body with HMAC-SHA256 using your secret key, sent in
     * either the `Chapa-Signature` or `x-chapa-signature` header.
     */

    public function webhook(Request $request): JsonResponse
    {
        if (!$this->verifyWebhookSignature($request)) {
            Log::warning('Chapa webhook: signature verification failed', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = $request->all();
        $txRef   = $payload['tx_ref'] ?? $payload['reference'] ?? null;

        if (!$txRef) {
            Log::warning('Chapa webhook: missing tx_ref in payload', ['payload' => $payload]);
            return response()->json(['status' => 'ignored']);
        }

        $payment = BillingPayment::where('chapa_tx_ref', $txRef)
            ->where('payment_channel', 'chapa')
            ->first();

        if (!$payment) {
            // Nothing we can do without a matching local record; ack anyway so Chapa doesn't retry forever.
            Log::warning('Chapa webhook: no matching payment for tx_ref', ['tx_ref' => $txRef]);
            return response()->json(['status' => 'ignored']);
        }

        try {
            $this->confirmPayment($payment);
        } catch (\Throwable $e) {
            Log::error('Chapa webhook: error confirming payment', [
                'tx_ref' => $txRef,
                'error'  => $e->getMessage(),
            ]);
        }

        // Always acknowledge with 200 so Chapa doesn't keep retrying.
        return response()->json(['status' => 'ok']);
    }

    /* ── Helpers ─────────────────────────────────────────────── */

    /**
     * Shared confirmation logic used by both the frontend-polled verify
     * endpoint and the webhook. Always re-checks status directly against
     * Chapa's API rather than trusting a webhook payload's status field.
     *
     * @return array{code:int, body:array}
     */
    private function confirmPayment(BillingPayment $payment): array
    {
        if ($payment->status === 'verified') {
            return [
                'code' => 200,
                'body' => [
                    'status'  => 'success',
                    'message' => 'Payment already verified.',
                    'invoice' => $this->invoiceSummary($payment->invoice),
                ],
            ];
        }

        $response = Http::withToken($this->secretKey)
            ->get($this->baseUrl . '/transaction/verify/' . $payment->chapa_tx_ref);

        if (!$response->successful()) {
            return [
                'code' => 502,
                'body' => ['message' => 'Could not reach Chapa to verify payment. Please try again.'],
            ];
        }

        $chapaData   = $response->json('data');
        $chapaStatus = $chapaData['status'] ?? 'failed';

        if ($chapaStatus !== 'success') {
            $payment->update(['status' => 'rejected', 'admin_notes' => 'Chapa status: ' . $chapaStatus]);
            return [
                'code' => 422,
                'body' => [
                    'status'  => 'failed',
                    'message' => 'Payment was not completed. Chapa status: ' . $chapaStatus,
                ],
            ];
        }

        // Payment confirmed — mark everything as paid and provision memberships
        return DB::transaction(function () use ($payment): array {
            $payment->update([
                'status'             => 'verified',
                'verified_at'        => now(),
                'chapa_verified_at'  => now(),
                'admin_notes'        => 'Auto-verified via Chapa',
            ]);

            $payment->invoice->update([
                'status'  => 'paid',
                'paid_at' => now(),
            ]);

            $result = MembershipService::provisionForInvoice($payment->invoice);

            return [
                'code' => 200,
                'body' => [
                    'status'              => 'success',
                    'message'             => sprintf(
                        'Payment confirmed! %d employee(s) now have active gym memberships.',
                        $result['memberships']
                    ),
                    'employees_updated'   => $result['employees'],
                    'memberships_created' => $result['memberships'],
                    'invoice'             => $this->invoiceSummary($payment->invoice->fresh()),
                ],
            ];
        });
    }

    /**
     * Per Chapa's webhook docs, both headers are signed with the dashboard-configured
     * Webhook Secret:
     *   - `x-chapa-signature`: HMAC-SHA256 of the raw request body
     *   - `chapa-signature`:   HMAC-SHA256 of the secret itself (a static value,
     *                          useful as a quick check without hashing the body)
     * Either one matching is sufficient.
     */
    private function verifyWebhookSignature(Request $request): bool
    {
        $rawBody = $request->getContent();

        $payloadSignature = $request->header('x-chapa-signature');
        if ($payloadSignature && hash_equals(hash_hmac('sha256', $rawBody, $this->webhookSecret), $payloadSignature)) {
            return true;
        }

        $staticSignature = $request->header('chapa-signature');
        if ($staticSignature && hash_equals(hash_hmac('sha256', $this->webhookSecret, $this->webhookSecret), $staticSignature)) {
            return true;
        }

        return false;
    }

    private function getMyCompany(): Company
    {
        $user    = auth()->user();
        $company = Company::where('contact_email', $user->email)->first()
            ?? ($user->company_id ? Company::find($user->company_id) : null);

        if (!$company) abort(403, 'No company linked to this account.');
        return $company;
    }

    private function invoiceSummary(BillingInvoice $inv): array
    {
        return [
            'id'             => $inv->id,
            'invoice_number' => $inv->invoice_number,
            'billing_period' => $inv->billing_period,
            'total_amount'   => $inv->total_amount,
            'status'         => $inv->status,
            'paid_at'        => $inv->paid_at?->toDateTimeString(),
        ];
    }
}
