<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class CompanyController extends Controller
{
    /** Public: all active companies for employee self-signup dropdown */
    public function publicList(): JsonResponse
    {
        $companies = Company::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'industry', 'city']);

        return response()->json(['data' => $companies]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $companies = Company::query()
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->tier, fn($q) => $q->where('tier', $request->tier))
            ->when($request->license_status, fn($q) => $q->where('business_license_status', $request->license_status))
            ->when(in_array($request->is_active, ['true', 'false'], true), fn($q) => $q->where('is_active', $request->is_active === 'true'))
            ->withCount([
                'employees',
                'employees as enrolled_employees_count' => fn($q) => $q->where('is_enrolled', true),
            ])
            ->latest()
            ->paginate(10);

        return CompanyResource::collection($companies);
    }

    /**
     * Admin-only: create a company + its HR user account in one step.
     * Business licence is optional here (admin may upload later).
     */
    public function adminCreate(Request $request): JsonResponse
    {
        $request->validate([
            'name'          => 'required|string|max:255',
            'industry'      => 'required|string|max:100',
            'tier'          => 'required|in:basic,basic_plus,platinum',
            'contact_person'=> 'required|string|max:255',
            'contact_email' => 'required|email|unique:companies,contact_email|unique:users,email',
            'contact_phone' => 'required|string|max:20',
            'city'          => 'nullable|string|max:100',
            'tin_number'    => 'nullable|string|max:50',
            'is_active'                => 'boolean',
            'hr_password'              => 'required|string|min:6',
            'preferred_payment_method' => 'nullable|in:cbe_transfer,telebirr_enterprise,awash_bank,chapa,cash,other',
            'business_license'         => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ], [
            'contact_email.unique' => 'This email is already registered.',
            'hr_password.min'      => 'Password must be at least 6 characters.',
        ]);

        $licensePath = null;
        if ($request->hasFile('business_license')) {
            $licensePath = $request->file('business_license')->store('licenses', 'public');
        }

        try {
            return DB::transaction(function () use ($request, $licensePath): JsonResponse {
                $isActive = $request->boolean('is_active', true);

                $companyData = [
                    'name'                     => $request->name,
                    'industry'                 => $request->industry,
                    'tier'                     => $request->tier,
                    'contact_person'           => $request->contact_person,
                    'contact_email'            => $request->contact_email,
                    'contact_phone'            => $request->contact_phone,
                    'city'                     => $request->city ?? 'Addis Ababa',
                    'tin_number'               => $request->tin_number,
                    'is_active'                => $isActive,
                    'preferred_payment_method' => $request->preferred_payment_method,
                ];

                // Only set license fields when a file was actually uploaded
                if ($licensePath) {
                    $companyData['business_license_path']   = $licensePath;
                    $companyData['business_license_status'] = 'pending';
                }

                $company = Company::create($companyData);

                $user = User::create([
                    'name'      => $request->contact_person,
                    'email'     => $request->contact_email,
                    'password'  => Hash::make($request->hr_password),
                    'role'      => 'company_hr',
                    'phone'     => $request->contact_phone,
                    'is_active' => $isActive,
                ]);

                AuditLog::record('created', $company);
                return response()->json([
                    'message' => "Company '{$company->name}' registered and HR account created.",
                    'company' => new CompanyResource($company),
                    'hr_email'=> $user->email,
                ], 201);
            });
        } catch (\Throwable $e) {
            if ($licensePath) Storage::disk('public')->delete($licensePath);
            throw $e;
        }
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Handle business licence upload
        if ($request->hasFile('business_license')) {
            $data['business_license_path'] = $request->file('business_license')
                ->store('licenses', 'public');
            $data['business_license_status'] = 'pending';
        }

        // Remove the file key — not a DB column
        unset($data['business_license']);

        $company = Company::create($data);
        AuditLog::record('created', $company);

        return response()->json(new CompanyResource($company), 201);
    }

    public function show(Company $company): CompanyResource
    {
        $company->load([
            'employees.user',
            'employees.activeMembership',
            'activeSubscription.plan',
            'invoices' => fn($q) => $q->latest()->limit(5),
        ]);
        return new CompanyResource($company);
    }

    public function update(StoreCompanyRequest $request, Company $company): CompanyResource
    {
        $data = $request->validated();

        // Handle business licence replacement
        if ($request->hasFile('business_license')) {
            // Delete the old file if it exists
            if ($company->business_license_path) {
                Storage::disk('public')->delete($company->business_license_path);
            }

            $data['business_license_path']   = $request->file('business_license')
                ->store('licenses', 'public');
            $data['business_license_status'] = 'pending'; // reset to pending on re-upload
        }

        unset($data['business_license']);

        $company->update($data);
        $old = $company->only(array_keys($data));
        $company->update($data);
        AuditLog::record('updated', $company, $old, $data);

        return new CompanyResource($company);
    }

    public function destroy(Company $company): JsonResponse
    {
        // Clean up licence file when company is deleted
        if ($company->business_license_path) {
            Storage::disk('public')->delete($company->business_license_path);
        }

        AuditLog::record('deleted', $company);
        $company->delete();

        return response()->json(['message' => 'Company deleted.']);
    }

    public function toggleActive(Company $company): JsonResponse
    {
        $newState = !$company->is_active;
        $company->update(['is_active' => $newState]);

        // Keep the HR user account in sync so they can (or cannot) log in
        $company->hrUser?->update(['is_active' => $newState]);

        AuditLog::record('updated', $company, ['is_active' => !$newState], ['is_active' => $newState]);
        return response()->json(['is_active' => $company->is_active]);
    }

    /**
     * Admin-only: approve or reject a company's business licence.
     * Approving also activates both the company record and the HR user account.
     */
    public function updateLicenseStatus(Request $request, Company $company): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:approved,rejected,expired',
        ]);

        $isApproved = $request->status === 'approved';

        $company->update([
            'business_license_status' => $request->status,
            // Auto-activate the company when licence is approved
            'is_active' => $isApproved ? true : $company->is_active,
        ]);

        // Activate (or keep deactivated) the HR user so they can log in
        if ($isApproved) {
            $company->hrUser?->update(['is_active' => true]);
        }

        // Telegram notification to HR user
        $hrUser = $company->hrUser;
        if ($hrUser) {
            $telegram = app(TelegramService::class);
            if ($isApproved) {
                $telegram->notifyUserApproved($hrUser, 'company');
            } else {
                $telegram->notifyUserRejected($hrUser, 'company', $request->reason ?? null);
            }
        }

        AuditLog::record('updated', $company, ['business_license_status' => $company->getOriginal('business_license_status')], ['business_license_status' => $request->status]);
        return response()->json([
            'message'                 => "Business licence marked as {$request->status}.",
            'business_license_status' => $company->business_license_status,
            'is_active'               => $company->is_active,
        ]);
    }
}
