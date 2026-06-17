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
use Illuminate\Support\Str;

class ChapaController extends Controller
{
    private string $secretKey;
    private string $baseUrl;
    private string $returnUrl;

    public function __construct()
    {
        $this->secretKey = config('services.chapa.secret_key');
        $this->baseUrl   = config('services.chapa.base_url');
        $this->returnUrl = config('services.chapa.return_url');
    }

    /* ── Initialize Chapa transaction ─────────────────────────── */

    public function initialize(BillingInvoice $billingInvoice): JsonResponse
    {
        $company = $this->getMyCompany();

        if ($billingInvoice->company_id !== $company->id) {
            abort(403);
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

        $txRef = $request->tx_ref;

        // Find the payment record
        $payment = BillingPayment::where('chapa_tx_ref', $txRef)
            ->where('payment_channel', 'chapa')
            ->first();

        if (!$payment) {
            return response()->json(['message' => 'Transaction not found.'], 404);
        }

        if ($payment->status === 'verified') {
            return response()->json([
                'status'  => 'success',
                'message' => 'Payment already verified.',
                'invoice' => $this->invoiceSummary($payment->invoice),
            ]);
        }

        // Call Chapa verify API
        $response = Http::withToken($this->secretKey)
            ->get($this->baseUrl . '/transaction/verify/' . $txRef);

        if (!$response->successful()) {
            return response()->json(['message' => 'Could not reach Chapa to verify payment. Please try again.'], 502);
        }

        $chapaData   = $response->json('data');
        $chapaStatus = $chapaData['status'] ?? 'failed';

        if ($chapaStatus !== 'success') {
            $payment->update(['status' => 'rejected', 'admin_notes' => 'Chapa status: ' . $chapaStatus]);
            return response()->json([
                'status'  => 'failed',
                'message' => 'Payment was not completed. Chapa status: ' . $chapaStatus,
            ], 422);
        }

        // Payment confirmed — mark everything as paid and provision memberships
        return DB::transaction(function () use ($payment): JsonResponse {
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

            $result = MembershipService::provisionForCompany($payment->company_id);

            return response()->json([
                'status'              => 'success',
                'message'             => sprintf(
                    'Payment confirmed! %d employee(s) now have active gym memberships.',
                    $result['memberships']
                ),
                'employees_updated'   => $result['employees'],
                'memberships_created' => $result['memberships'],
                'invoice'             => $this->invoiceSummary($payment->invoice->fresh()),
            ]);
        });
    }

    /* ── Helpers ─────────────────────────────────────────────── */

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
