<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyApplication;
use App\Models\CompanyBranch;
use App\Models\User;
use App\Services\ApplicationMailer;
use App\Services\BranchLoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin review of employer applications submitted from the public form.
 * Approving creates the company, its HR login and every branch login, each
 * with a temporary password that is emailed and must be changed at first sign-in.
 */
class CompanyApplicationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => 'nullable|in:pending,approved,rejected']);

        $apps = CompanyApplication::query()
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->get();

        return response()->json([
            'data'          => $apps->map(fn(CompanyApplication $a) => [
                'id'                       => $a->id,
                'reference'                => $a->reference,
                'company_name'             => $a->company_name,
                'industry'                 => $a->industry,
                'company_size'             => $a->company_size,
                'tin_number'               => $a->tin_number,
                'preferred_payment_method' => $a->preferred_payment_method,
                'contact_name'             => $a->contact_name,
                'job_title'                => $a->job_title,
                'contact_email'            => $a->contact_email,
                'contact_phone'            => $a->contact_phone,
                'branches'                 => $a->branches ?? [],
                'status'                   => $a->status,
                'rejection_reason'         => $a->rejection_reason,
                'company_id'               => $a->company_id,
                'submitted_at'             => $a->created_at?->toIso8601String(),
                'reviewed_at'              => $a->reviewed_at?->toIso8601String(),
            ]),
            'pending_count' => CompanyApplication::where('status', 'pending')->count(),
        ]);
    }

    public function approve(Request $request, CompanyApplication $companyApplication): JsonResponse
    {
        $request->validate(['tier' => 'required|in:basic,basic_plus,platinum']);

        $app = $companyApplication;
        if ($app->status !== 'pending') {
            return response()->json(['message' => 'This application has already been processed.'], 422);
        }

        // Emails may have been taken since the application was submitted
        $emails = array_merge([$app->contact_email], array_column($app->branches ?? [], 'email'));
        $taken = User::whereIn('email', $emails)->pluck('email')->all();
        if ($taken || Company::where('contact_email', $app->contact_email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'These email addresses are already registered: ' . implode(', ', $taken ?: [$app->contact_email])
                         . '. Reject this application or ask the applicant to use a different email.',
            ]);
        }

        /** @var array<int, array{name:string,email:string,password:string,label:string}> $logins */
        $logins = [];

        $company = DB::transaction(function () use ($request, $app, &$logins): Company {
            $company = Company::create([
                'name'                     => $app->company_name,
                'industry'                 => $app->industry,
                'tier'                     => $request->tier,
                'contact_person'           => $app->contact_name,
                'contact_email'            => $app->contact_email,
                'contact_phone'            => $app->contact_phone,
                'city'                     => 'Addis Ababa',
                'tin_number'               => $app->tin_number,
                'preferred_payment_method' => $app->preferred_payment_method,
                // Verified by the admin as part of this review
                'business_license_status'  => 'approved',
                'is_active'                => true,
            ]);

            // Head-office HR login (linked to the company by its contact email)
            $hrPassword = self::tempPassword();
            User::create([
                'name'                => $app->contact_name,
                'email'               => $app->contact_email,
                'password'            => Hash::make($hrPassword),
                'role'                => 'company_hr',
                'phone'               => $app->contact_phone,
                'is_active'           => true,
                'must_reset_password' => true,
            ]);
            $logins[] = ['name' => $app->contact_name, 'email' => $app->contact_email,
                         'password' => $hrPassword, 'label' => $company->name];

            foreach ($app->branches ?? [] as $b) {
                $branch = CompanyBranch::create([
                    'company_id'     => $company->id,
                    'name'           => $b['branch_name'],
                    'contact_person' => $b['contact_person'],
                    'city'           => $b['city'],
                    'sub_city'       => $b['sub_city'] ?? null,
                    'address'        => $b['woreda'],
                    'landmark'       => $b['landmark'] ?? null,
                    'latitude'       => $b['latitude'] ?? null,
                    'longitude'      => $b['longitude'] ?? null,
                    'phone'          => $b['contact_phone'] ?? null,
                    'is_active'      => true,
                ]);

                $branchPassword = self::tempPassword();
                BranchLoginService::createLogin(array_merge($b, ['password' => $branchPassword]), 'company_hr', [
                    'company_id'          => $company->id,
                    'company_branch_id'   => $branch->id,
                    'is_active'           => true,
                    'must_reset_password' => true,
                ]);
                $logins[] = ['name' => $b['contact_person'], 'email' => $b['email'],
                             'password' => $branchPassword, 'label' => "{$company->name} – {$branch->name}"];
            }

            $app->update([
                'status'      => 'approved',
                'company_id'  => $company->id,
                'reviewed_by' => $request->user()?->id,
                'reviewed_at' => now(),
            ]);

            AuditLog::record('created', $company);

            return $company;
        });

        // Email AFTER commit; report which ones went out so the admin can follow up
        $credentials = array_map(fn($l) => [
            'name'          => $l['name'],
            'email'         => $l['email'],
            'temp_password' => $l['password'],
            'label'         => $l['label'],
            'emailed'       => ApplicationMailer::credentials(ApplicationMailer::COMPANY, $l['name'], $l['email'], $l['password'], $l['label']),
        ], $logins);

        return response()->json([
            'message'     => "\"{$company->name}\" approved. Login details were sent by email.",
            'company_id'  => $company->id,
            // Shown to the admin once, in case an email does not arrive
            'credentials' => $credentials,
        ]);
    }

    public function reject(Request $request, CompanyApplication $companyApplication): JsonResponse
    {
        $request->validate(['reason' => 'nullable|string|max:500']);

        $app = $companyApplication;
        if ($app->status !== 'pending') {
            return response()->json(['message' => 'This application has already been processed.'], 422);
        }

        $app->update([
            'status'           => 'rejected',
            'rejection_reason' => $request->reason,
            'reviewed_by'      => $request->user()?->id,
            'reviewed_at'      => now(),
        ]);

        ApplicationMailer::rejected(ApplicationMailer::COMPANY, $app->contact_name, $app->contact_email,
            $app->reference, $app->company_name, $request->reason);

        return response()->json(['message' => 'Application rejected. The applicant has been informed by email.']);
    }

    /** Strong enough for StrongPassword, readable enough to type from an email */
    public static function tempPassword(): string
    {
        return Str::password(12, symbols: false) . '#' . random_int(10, 99);
    }
}
