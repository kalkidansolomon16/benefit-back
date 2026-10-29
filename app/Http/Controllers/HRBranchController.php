<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Employee;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Services\BranchLoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Branch management for the company (HR) portal.
 * Every branch has its own login. Only the head office (main company login)
 * can create / edit / remove branches; branch logins can only look at the list.
 */
class HRBranchController extends Controller
{
    private function getMyCompany(): Company
    {
        $user = auth()->user();

        if ($user->company_id && ($company = Company::find($user->company_id))) {
            return $company;
        }

        $company = Company::where('contact_email', $user->email)->first();
        if (!$company) abort(403, 'No company is associated with this account.');

        return $company;
    }

    private function requireHeadOffice(): void
    {
        $user = auth()->user();
        if ($user->isCompanyBranchLogin() || !$user->hasPermission('co.team.view')) {
            abort(403, 'Only the head office can manage branches.');
        }
    }

    private function branchOfCompany(Company $company, CompanyBranch $branch): void
    {
        if ($branch->company_id !== $company->id) abort(404);
    }

    private function payload(CompanyBranch $b, ?int $employeeCount = null): array
    {
        $login = $b->loginUser;

        return [
            'id'              => $b->id,
            'name'            => $b->name,
            'contact_person'  => $b->contact_person,
            'city'            => $b->city,
            'sub_city'        => $b->sub_city,
            'address'         => $b->address,
            'landmark'        => $b->landmark,
            'phone'           => $b->phone,
            'is_active'       => $b->is_active,
            'login_email'     => $login?->email,
            'employee_count'  => $employeeCount ?? $b->employees()->count(),
        ];
    }

    /** All HR logins of the company can see the branch list */
    public function index(): JsonResponse
    {
        $company = $this->getMyCompany();
        $user    = auth()->user();

        $counts = Employee::where('company_id', $company->id)
            ->selectRaw('company_branch_id, count(*) as c')
            ->groupBy('company_branch_id')
            ->pluck('c', 'company_branch_id');

        $isHeadOffice = !$user->isCompanyBranchLogin();

        return response()->json([
            'head_office' => [
                'name'           => $company->name . ' (Head Office)',
                'city'           => $company->city,
                'address'        => $company->address,
                'employee_count' => (int) ($counts[''] ?? $counts[null] ?? 0),
            ],
            'branches' => $company->branches()->with('loginUser:id,email,company_branch_id')->orderBy('name')->get()
                ->map(fn($b) => array_merge($this->payload($b, (int) ($counts[$b->id] ?? 0)), [
                    'is_mine' => (int) $user->company_branch_id === $b->id,
                ]))->values(),
            'can_manage'       => $isHeadOffice && $user->hasPermission('co.team.view'),
            'my_branch_id'     => $user->company_branch_id,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireHeadOffice();
        $company = $this->getMyCompany();

        $branch = BranchLoginService::validate([$request->all()], BranchLoginService::COMPANY, [$company->contact_email])[0];

        $created = DB::transaction(function () use ($company, $branch): CompanyBranch {
            $row = CompanyBranch::create([
                'company_id'     => $company->id,
                'name'           => $branch['branch_name'],
                'contact_person' => $branch['contact_person'],
                'city'           => $branch['city'],
                'sub_city'       => $branch['sub_city'],
                'address'        => $branch['woreda'],
                'landmark'       => $branch['landmark'],
                'phone'          => $branch['contact_phone'],
                'is_active'      => true,
            ]);

            // The company is already approved, so the login works straight away
            BranchLoginService::createLogin($branch, 'company_hr', [
                'company_id'        => $company->id,
                'company_branch_id' => $row->id,
                'is_active'         => (bool) $company->is_active,
            ]);

            return $row;
        });

        AuditLog::record('created', $created);

        return response()->json([
            'message' => "Branch \"{$created->name}\" created. They can sign in with their own email and password.",
            'branch'  => $this->payload($created->load('loginUser:id,email,company_branch_id'), 0),
        ], 201);
    }

    public function update(Request $request, CompanyBranch $branch): JsonResponse
    {
        $this->requireHeadOffice();
        $company = $this->getMyCompany();
        $this->branchOfCompany($company, $branch);

        $data = $request->validate([
            'name'           => 'sometimes|required|string|max:100',
            'contact_person' => 'sometimes|required|string|max:255',
            'city'           => 'sometimes|required|string|max:100',
            'sub_city'       => 'nullable|string|max:100',
            'address'        => 'sometimes|required|string|max:255',
            'landmark'       => 'nullable|string|max:255',
            'phone'          => 'nullable|string|max:20',
            'is_active'      => 'sometimes|boolean',
        ]);

        $branch->update($data);

        // Switching a branch off also blocks its login
        if (array_key_exists('is_active', $data)) {
            $branch->loginUser?->update(['is_active' => $data['is_active'] && $company->is_active]);
            if (!$data['is_active']) {
                $branch->loginUser?->tokens()->delete();
            }
        }
        if (isset($data['contact_person'])) {
            $branch->loginUser?->update(['name' => $data['contact_person']]);
        }

        return response()->json([
            'message' => 'Branch updated.',
            'branch'  => $this->payload($branch->fresh()->load('loginUser:id,email,company_branch_id')),
        ]);
    }

    public function destroy(CompanyBranch $branch): JsonResponse
    {
        $this->requireHeadOffice();
        $company = $this->getMyCompany();
        $this->branchOfCompany($company, $branch);

        if ($branch->employees()->exists()) {
            return response()->json([
                'message' => 'This branch still has employees. Deactivate it instead, or move its employees first.',
            ], 422);
        }

        $login = $branch->loginUser;
        $login?->tokens()->delete();
        $login?->forceDelete();
        $branch->delete();

        return response()->json(['message' => 'Branch removed.']);
    }

    /** Head office issues a new temporary password for a branch login */
    public function resetPassword(CompanyBranch $branch): JsonResponse
    {
        $this->requireHeadOffice();
        $company = $this->getMyCompany();
        $this->branchOfCompany($company, $branch);

        $login = $branch->loginUser;
        if (!$login) {
            return response()->json(['message' => 'This branch has no login.'], 404);
        }

        $temp = Str::password(14);
        $login->update(['password' => Hash::make($temp), 'must_reset_password' => true]);
        $login->tokens()->delete();

        return response()->json([
            'message'       => 'Temporary password issued. Share it securely — it is shown only once.',
            'temp_password' => $temp,
            'email'         => $login->email,
        ]);
    }
}
