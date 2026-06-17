<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CompanyUserController extends Controller
{
    private function getMyCompany(): Company
    {
        $user = auth()->user();
        if ($user->company_id) return Company::findOrFail($user->company_id);
        $co = Company::where('contact_email', $user->email)->first();
        if (!$co) abort(403, 'No company linked to this account.');
        return $co;
    }

    private function requiresManage(): void
    {
        if (!auth()->user()->hasPermission('co.team.view')) {
            abort(403, 'You do not have permission to manage team members.');
        }
    }

    /* ── List company sub-users ──────────────────────────────── */

    public function index(): JsonResponse
    {
        $this->requiresManage();
        $company = $this->getMyCompany();

        $subRoles = Role::where('scope', 'company')
            ->where(fn($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))
            ->where('name', '!=', 'company_hr')
            ->pluck('name')->toArray();

        $users = User::whereIn('role', array_merge(User::COMPANY_SUB_ROLES, $subRoles))
            ->where('company_id', $company->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($u) => $this->formatUser($u));

        return response()->json($users);
    }

    /* ── Create company sub-user ─────────────────────────────── */

    public function store(Request $request): JsonResponse
    {
        $this->requiresManage();
        $company = $this->getMyCompany();

        $company    = $this->getMyCompany();
        $validRoles = Role::where('scope', 'company')
            ->where(fn($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))
            ->where('name', '!=', 'company_hr')   // HR is the primary role, not assignable as sub
            ->pluck('name')->toArray();

        $request->validate([
            'name'  => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'role'  => ['required', 'string', 'in:' . implode(',', $validRoles)],
        ]);

        $tempPassword = Str::random(6) . rand(10, 99) . '!';

        $user = User::create([
            'name'                => $request->name,
            'email'               => $request->email,
            'password'            => Hash::make($tempPassword),
            'role'                => $request->role,
            'is_active'           => false,
            'must_reset_password' => true,
            'created_by'          => auth()->id(),
            'company_id'          => $company->id,
        ]);

        return response()->json([
            'message'       => 'Team member created. Activate the account after sharing credentials.',
            'user'          => $this->formatUser($user),
            'temp_password' => $tempPassword,
        ], 201);
    }

    /* ── Activate / Deactivate ───────────────────────────────── */

    public function toggleActive(User $user): JsonResponse
    {
        $this->requiresManage();
        $this->assertOwnership($user);

        $user->update(['is_active' => !$user->is_active]);

        return response()->json([
            'message' => $user->is_active ? 'User activated.' : 'User deactivated.',
            'user'    => $this->formatUser($user->fresh()),
        ]);
    }

    /* ── Regenerate temp password ────────────────────────────── */

    public function resetTemp(User $user): JsonResponse
    {
        $this->requiresManage();
        $this->assertOwnership($user);

        $tempPassword = Str::random(6) . rand(10, 99) . '!';
        $user->update(['password' => Hash::make($tempPassword), 'must_reset_password' => true]);

        return response()->json(['message' => 'Password reset.', 'temp_password' => $tempPassword]);
    }

    /* ── Delete user ─────────────────────────────────────────── */

    public function destroy(User $user): JsonResponse
    {
        $this->requiresManage();
        $this->assertOwnership($user);
        $user->delete();
        return response()->json(['message' => 'Team member removed.']);
    }

    /* ── Company-scope permissions ───────────────────────────── */

    public function permissions(): JsonResponse
    {
        $this->requiresManage();

        $allPerms = Permission::where('scope', 'company')
            ->orderBy('group_name')->orderBy('label')->get();

        $rolePerms = RolePermission::whereIn('role', array_merge(['company_hr'], User::COMPANY_SUB_ROLES))
            ->get()->groupBy('role');

        return response()->json(['permissions' => $allPerms, 'role_permissions' => $rolePerms]);
    }

    public function updateRolePermissions(Request $request, string $role): JsonResponse
    {
        $this->requiresManage();

        if (!in_array($role, User::COMPANY_SUB_ROLES)) {
            abort(422, 'Only sub-role permissions can be edited.');
        }

        $request->validate(['permissions' => 'required|array']);

        RolePermission::where('role', $role)->delete();
        foreach ($request->permissions as $perm) {
            RolePermission::create(['role' => $role, 'permission_name' => $perm]);
        }

        return response()->json(['message' => "Permissions updated for {$role}."]);
    }

    public function updateUserPermissions(Request $request, User $user): JsonResponse
    {
        $this->requiresManage();
        $this->assertOwnership($user);

        $request->validate([
            'overrides'           => 'required|array',
            'overrides.*.name'    => 'required|string',
            'overrides.*.granted' => 'required|boolean',
        ]);

        UserPermission::where('user_id', $user->id)->delete();
        foreach ($request->overrides as $o) {
            UserPermission::create(['user_id' => $user->id, 'permission_name' => $o['name'], 'granted' => $o['granted']]);
        }

        return response()->json(['message' => 'User permissions updated.']);
    }

    /* ── Helpers ─────────────────────────────────────────────── */

    private function assertOwnership(User $user): void
    {
        $company = $this->getMyCompany();
        if ($user->company_id !== $company->id || !in_array($user->role, User::COMPANY_SUB_ROLES)) {
            abort(403);
        }
    }

    private function formatUser(User $u): array
    {
        return [
            'id'                  => $u->id,
            'name'                => $u->name,
            'email'               => $u->email,
            'role'                => $u->role,
            'is_active'           => $u->is_active,
            'must_reset_password' => $u->must_reset_password,
            'created_at'          => $u->created_at?->toDateString(),
        ];
    }
}
