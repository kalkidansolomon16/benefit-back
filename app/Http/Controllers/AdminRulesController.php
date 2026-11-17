<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Services\ContractPack;
use App\Services\OnboardingDocuments;
use App\Support\BillingCalendar;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin → Rules & documents: payment calendar, late fee, deadlines, and the
 * employee contract that companies receive.
 */
class AdminRulesController extends Controller
{
    public function show(): JsonResponse
    {
        $values = Settings::all();
        $path = $values['contract_template_path'] ?? null;
        $hasFile = $path && Storage::disk('public')->exists($path);

        return response()->json([
            'rules' => collect(Settings::RULES)->map(fn ($r, $key) => [
                'key' => $key, 'value' => (int) $values[$key], 'default' => $r[0], 'label' => $r[1], 'help' => $r[2],
            ])->values(),
            'summary'  => BillingCalendar::rulesText(),
            'app_links' => [
                'android' => $values['mobile_app_android_url'] ?? null,
                'ios'     => $values['mobile_app_ios_url'] ?? null,
            ],
            'contract' => [
                'custom'      => $hasFile,
                'name'        => $hasFile ? ($values['contract_template_name'] ?? basename($path)) : 'Employee Enrolment and Consent Form (FA-EMP-01, PDF)',
                'uploaded_at' => $hasFile ? ($values['contract_template_uploaded_at'] ?? null) : null,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'staff_list_cutoff_day'       => 'required|integer|min:1|max:28',
            'invoice_draft_day'           => 'required|integer|min:1|max:28|gt:staff_list_cutoff_day',
            'invoice_send_day'            => 'required|integer|min:1|max:28|gte:invoice_draft_day',
            'payment_due_day'             => 'required|integer|min:1|max:31|gt:invoice_send_day',
            'grace_end_day'               => 'required|integer|min:1|max:28',
            'late_fee_percent'            => 'required|integer|min:0|max:50',
            'negotiation_extension_days'  => 'required|integer|min:1|max:30',
            'company_signup_deadline_day' => 'required|integer|min:1|max:28',
            'partner_apply_deadline_day'  => 'required|integer|min:1|max:28',
            'partner_listing_day'         => 'required|integer|min:1|max:28|gt:partner_apply_deadline_day',
            'invite_link_days'            => 'required|integer|min:1|max:90',
            'partner_statement_day'       => 'required|integer|min:1|max:28',
            'partner_payment_day'         => 'required|integer|min:1|max:28|gte:partner_statement_day',
            'renewal_reminder_days'       => 'required|integer|min:1|max:30',
        ], [
            'invoice_draft_day.gt'    => 'Invoices must be drafted after the staff-list cut-off.',
            'invoice_send_day.gte'    => 'Invoices are sent on or after the day they are drafted.',
            'payment_due_day.gt'      => 'The payment due day must be after the day invoices are sent.',
            'partner_listing_day.gt'  => 'Partners are listed after the application deadline.',
            'partner_payment_day.gte' => 'Partners are paid on or after the statement day.',
        ]);

        $old = array_intersect_key(Settings::all(), $data);
        Settings::put($data);
        AuditLog::record('updated', $request->user(), $old, ['billing_rules' => $data]);

        return response()->json(['message' => 'Rules saved.', 'summary' => BillingCalendar::rulesText()]);
    }

    /** Mobile app store links shown in employee emails (invitation, card number) */
    public function updateAppLinks(Request $request): JsonResponse
    {
        $data = $request->validate([
            'android' => 'nullable|url:https|max:500',
            'ios'     => 'nullable|url:https|max:500',
        ]);
        Settings::put(['mobile_app_android_url' => $data['android'] ?? null, 'mobile_app_ios_url' => $data['ios'] ?? null]);

        return response()->json(['message' => 'App links saved. Employee emails now include them.']);
    }
    public function uploadContract(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:docx,doc,pdf|max:10240']);
        $file = $request->file('file');

        $old = Settings::get('contract_template_path');
        $path = $file->storeAs('contracts', 'employee-contract-' . now()->format('YmdHis') . '.' . strtolower($file->getClientOriginalExtension()), 'public');
        Settings::put([
            'contract_template_path'        => $path,
            'contract_template_name'        => $file->getClientOriginalName(),
            'contract_template_uploaded_at' => now()->toIso8601String(),
        ]);
        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        return response()->json(['message' => 'Contract uploaded. Companies will receive this file from now on.']);
    }

    public function resetContract(): JsonResponse
    {
        $old = Settings::get('contract_template_path');
        if ($old) {
            Storage::disk('public')->delete($old);
        }
        Settings::put(['contract_template_path' => null, 'contract_template_name' => null, 'contract_template_uploaded_at' => null]);

        return response()->json(['message' => 'Back to the official Employee Enrolment and Consent Form (FA-EMP-01).']);
    }

    public function downloadContract(): Response
    {
        $doc = OnboardingDocuments::contract();

        return response($doc['content'], 200, [
            'Content-Type'        => $doc['mime'],
            'Content-Disposition' => 'attachment; filename="' . $doc['name'] . '"',
        ]);
    }

    public function downloadTemplate(Request $request): Response
    {
        $company = $request->filled('company_id') ? Company::find($request->company_id) : null;

        return response(OnboardingDocuments::employeeTemplate($company), 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . OnboardingDocuments::templateFilename($company) . '"',
        ]);
    }

    /** Send (again) the contract + template to a company, with a fresh import deadline */
    public function sendContract(Company $company): JsonResponse
    {
        ContractPack::send($company);

        return response()->json([
            'message'         => "Contract and Excel template sent to {$company->name}. Import deadline: " . $company->fresh()->import_deadline?->format('j M Y') . '.',
            'import_deadline' => $company->fresh()->import_deadline?->toDateString(),
        ]);
    }
}
