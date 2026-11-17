<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Gym;
use App\Models\PartnerStatement;
use App\Services\Notifier;
use App\Services\PartnerStatements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Partner portal → Statements: the gym sees FitAccess's monthly statement of
 * visits, uploads its VAT/TOT invoice, and downloads FitAccess's payment receipt.
 */
class PartnerStatementController extends Controller
{
    /** Gyms this login may see (an owner sees every branch) */
    private function gymIds(): array
    {
        return Gym::viewableBy(auth()->user())->pluck('id')->all();
    }

    private function authorizeStatement(PartnerStatement $s): void
    {
        abort_unless(in_array($s->gym_id, $this->gymIds(), true), 404);
        abort_if($s->status === 'draft', 404); // not sent yet
    }

    public function index(): JsonResponse
    {
        $items = PartnerStatement::with('gym:id,name,branch_name,tier')
            ->whereIn('gym_id', $this->gymIds())
            ->where('status', '!=', 'draft')
            ->orderByDesc('period')
            ->limit(120)->get();

        return response()->json(['data' => $items->map(fn ($s) => PartnerStatements::format($s))->values()]);
    }

    public function show(PartnerStatement $partnerStatement): JsonResponse
    {
        $this->authorizeStatement($partnerStatement);

        return response()->json(PartnerStatements::format($partnerStatement));
    }

    /** Upload the gym's VAT or TOT invoice for the statement amount */
    public function uploadInvoice(Request $request, PartnerStatement $partnerStatement): JsonResponse
    {
        $this->authorizeStatement($partnerStatement);
        if ($partnerStatement->status === 'paid') {
            return response()->json(['message' => 'This statement is already paid.'], 422);
        }

        $data = $request->validate([
            'file'           => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'invoice_type'   => 'required|in:VAT,TOT',
            'invoice_number' => 'required|string|max:80',
            'amount'         => 'required|numeric|min:0|max:100000000',
            'note'           => 'nullable|string|max:1000',
        ]);

        $file = $request->file('file');
        $old = $partnerStatement->partner_invoice_path;
        $path = $file->storeAs('partner-statements/' . $partnerStatement->id, 'invoice-' . now()->format('YmdHis') . '.' . strtolower($file->getClientOriginalExtension()), 'local');
        $partnerStatement->update([
            'status'                 => 'invoiced',
            'partner_invoice_path'   => $path,
            'partner_invoice_name'   => mb_substr($file->getClientOriginalName(), 0, 250),
            'partner_invoice_type'   => $data['invoice_type'],
            'partner_invoice_number' => $data['invoice_number'],
            'partner_invoice_amount' => $data['amount'],
            'partner_note'           => $data['note'] ?? null,
            'invoiced_at'            => now(),
        ]);
        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }
        AuditLog::record('updated', $partnerStatement, ['status' => 'sent'], ['status' => 'invoiced', 'invoice_number' => $data['invoice_number']]);

        $s = $partnerStatement->fresh('gym');
        $mismatch = abs((float) $data['amount'] - (float) $s->total_amount) > 0.01;
        Notifier::admins('partner_invoice', "{$s->gym->name} sent its {$data['invoice_type']} invoice for {$s->period_start->format('F Y')}",
            "Invoice {$data['invoice_number']}: ETB " . number_format((float) $data['amount'], 2)
            . ($mismatch ? ' (statement says ETB ' . number_format((float) $s->total_amount, 2) . ')' : '')
            . ($s->partner_note ? ". Note: {$s->partner_note}" : '') . '. Pay it and upload the receipt in Partner statements.',
            ['partner_statement_id' => $s->id], true);
        Notifier::gym($s->gym, 'partner_invoice_received', 'Invoice received',
            "FitAccess received your invoice {$data['invoice_number']} for {$s->period_start->format('F Y')}. You will be notified when it is paid.", null);

        return response()->json(['message' => 'Invoice uploaded. FitAccess will pay it and share the receipt here.', 'statement' => PartnerStatements::format($s)]);
    }

    public function file(PartnerStatement $partnerStatement, string $kind): Response
    {
        $this->authorizeStatement($partnerStatement);
        [$path, $name] = $kind === 'invoice'
            ? [$partnerStatement->partner_invoice_path, $partnerStatement->partner_invoice_name]
            : [$partnerStatement->receipt_path, $partnerStatement->receipt_name];
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $name ?: basename($path));
    }
}
