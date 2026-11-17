<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PartnerStatement;
use App\Services\Notifier;
use App\Services\PartnerStatements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin → Partner statements: monthly visits per gym and what FitAccess owes.
 * draft → sent → invoiced (gym uploaded its VAT/TOT invoice) → paid (receipt uploaded).
 */
class AdminPartnerStatementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $period = $request->query('period');
        $items = PartnerStatement::with('gym:id,name,branch_name,tier,contact_email,per_visit_rate,visit_rates')
            ->when($period, fn ($q) => $q->where('period', $period))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('period')->orderBy('gym_id')
            ->limit(500)->get();

        return response()->json([
            'data'    => $items->map(fn ($s) => PartnerStatements::format($s, true))->values(),
            'periods' => PartnerStatement::distinct()->orderByDesc('period')->pluck('period')->values(),
            'totals'  => [
                'amount'      => (float) $items->sum('total_amount'),
                'visits'      => (int) $items->sum('total_visits'),
                'to_send'     => $items->where('status', 'draft')->count(),
                'to_pay'      => $items->where('status', 'invoiced')->count(),
            ],
        ]);
    }

    public function show(PartnerStatement $partnerStatement): JsonResponse
    {
        return response()->json(PartnerStatements::format($partnerStatement, true));
    }

    /** Create / refresh draft statements for a month (default: last month) */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => 'nullable|date_format:Y-m', 'gym_id' => 'nullable|exists:gyms,id']);
        $month = !empty($data['period']) ? Carbon::createFromFormat('!Y-m', $data['period']) : now()->subMonthNoOverflow()->startOfMonth();
        $n = PartnerStatements::generate($month, $data['gym_id'] ?? null);

        return response()->json([
            'message' => $n ? "{$n} statement(s) prepared for " . $month->format('F Y') . '.' : 'No gym had FitAccess visits in ' . $month->format('F Y') . '.',
            'count'   => $n,
            'period'  => $month->format('Y-m'),
        ]);
    }

    /** Change the gym's per-tier visit rates and recalculate this statement */
    public function updateRates(Request $request, PartnerStatement $partnerStatement): JsonResponse
    {
        if (!$partnerStatement->isEditable()) {
            return response()->json(['message' => 'This statement is already invoiced or paid and can no longer change.'], 422);
        }
        $rules = [];
        foreach (array_merge(PartnerStatements::TIERS, ['other']) as $t) {
            $rules["rates.{$t}"] = 'nullable|numeric|min:0|max:100000';
        }
        $data = $request->validate($rules + ['rates' => 'required|array']);

        $gym = $partnerStatement->gym;
        $old = $gym->visit_rates;
        $gym->update(['visit_rates' => array_map(fn ($v) => $v === null || $v === '' ? null : (float) $v, $data['rates'])]);
        AuditLog::record('updated', $gym, ['visit_rates' => $old], ['visit_rates' => $gym->visit_rates]);

        $s = PartnerStatements::refresh($partnerStatement);
        if ($s->status === 'sent') {
            PartnerStatements::send($s);   // the gym receives the corrected statement
        }

        return response()->json(['message' => 'Rates saved and statement recalculated.', 'statement' => PartnerStatements::format($s->fresh(), true)]);
    }

    public function send(PartnerStatement $partnerStatement): JsonResponse
    {
        if (!$partnerStatement->isEditable()) {
            return response()->json(['message' => 'This statement was already invoiced or paid.'], 422);
        }
        PartnerStatements::send($partnerStatement);

        return response()->json(['message' => 'Statement sent to ' . $partnerStatement->gym->name . '.', 'statement' => PartnerStatements::format($partnerStatement->fresh(), true)]);
    }

    public function sendAll(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => 'nullable|date_format:Y-m']);
        $drafts = PartnerStatement::with('gym')->where('status', 'draft')
            ->when($data['period'] ?? null, fn ($q, $p) => $q->where('period', $p))->get();
        foreach ($drafts as $s) {
            PartnerStatements::send($s);
        }

        return response()->json(['message' => $drafts->count() . ' statement(s) sent to the gyms.', 'count' => $drafts->count()]);
    }

    /** FitAccess paid the gym: upload the payment receipt */
    public function pay(Request $request, PartnerStatement $partnerStatement): JsonResponse
    {
        if ($partnerStatement->status === 'paid') {
            return response()->json(['message' => 'This statement is already paid.'], 422);
        }
        if (!in_array($partnerStatement->status, ['sent', 'invoiced'], true)) {
            return response()->json(['message' => 'Send the statement to the gym first.'], 422);
        }
        $data = $request->validate([
            'receipt'   => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'reference' => 'nullable|string|max:120',
            'note'      => 'nullable|string|max:1000',
        ]);

        $file = $request->file('receipt');
        $path = $file->storeAs('partner-statements/' . $partnerStatement->id, 'receipt-' . now()->format('YmdHis') . '.' . strtolower($file->getClientOriginalExtension()), 'local');
        $partnerStatement->update([
            'status'            => 'paid',
            'receipt_path'      => $path,
            'receipt_name'      => mb_substr($file->getClientOriginalName(), 0, 250),
            'payment_reference' => $data['reference'] ?? null,
            'admin_note'        => $data['note'] ?? null,
            'paid_at'           => now(),
            'paid_by'           => auth()->id(),
        ]);
        PartnerStatements::mirrorPayout($partnerStatement->fresh());
        AuditLog::record('updated', $partnerStatement, ['status' => 'invoiced'], ['status' => 'paid', 'reference' => $data['reference'] ?? null]);

        $s = $partnerStatement->fresh('gym');
        $amount = 'ETB ' . number_format((float) $s->total_amount, 2);
        Notifier::gym($s->gym, 'partner_paid', "FitAccess paid your {$s->period_start->format('F Y')} statement",
            "FitAccess has paid {$amount} for {$s->period_start->format('F Y')}" . ($s->payment_reference ? " (reference {$s->payment_reference})" : '')
            . '. The payment receipt is in the partner portal under Statements.',
            [
                'highlight'  => "Paid: {$amount}",
                'details'    => array_filter(['Period' => $s->period_start->format('F Y'), 'Amount' => $amount, 'Reference' => $s->payment_reference, 'Note' => $s->admin_note]),
                'buttonText' => 'View receipt',
                'buttonUrl'  => config('app.frontend_url') . '/partner/statements',
            ]);

        return response()->json(['message' => 'Payment recorded and the receipt was shared with ' . $s->gym->name . '.', 'statement' => PartnerStatements::format($s, true)]);
    }

    /** Download the gym's invoice or the payment receipt */
    public function file(PartnerStatement $partnerStatement, string $kind): Response
    {
        [$path, $name] = $kind === 'invoice'
            ? [$partnerStatement->partner_invoice_path, $partnerStatement->partner_invoice_name]
            : [$partnerStatement->receipt_path, $partnerStatement->receipt_name];
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $name ?: basename($path));
    }
}
