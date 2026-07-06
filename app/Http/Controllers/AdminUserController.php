<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserController extends Controller
{
    private function requiresManage(): void
    {
        if (!auth()->user()->hasPermission('team.view')) {
            abort(403, 'You do not have permission to manage team members.');
        }
    }

    /* ── List all admin sub-users ────────────────────────────── */

    public function index(): JsonResponse
    {
        $this->requiresManage();

        $users = User::whereIn('role', User::ADMIN_SUB_ROLES)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($u) => $this->formatUser($u));

        return response()->json($users);
    }

    /* ── Create a new admin sub-user ────────────────────────── */

    public function store(Request $request): JsonResponse
    {
        $this->requiresManage();

        // Validate role against the admin roles catalogue
        $validRoles = Role::where('scope', 'admin')
            ->where('name', '!=', 'fitaccess_admin')  // can't assign top admin role
            ->pluck('name')->toArray();

        $request->validate([
            'name'  => 'required|string|max:100',
            'email' => ['required', 'email', \Illuminate\Validation\Rule::unique('users', 'email')->whereNull('deleted_at')],
            'role'  => ['required', 'string', 'in:' . implode(',', $validRoles)],
        ]);

        // Generate a readable temporary password
        $tempPassword = Str::random(6) . rand(10, 99) . '!';

        $user = User::create([
            'name'                => $request->name,
            'email'               => $request->email,
            'password'            => Hash::make($tempPassword),
            'role'                => $request->role,
            'is_active'           => false,  // admin must activate
            'must_reset_password' => true,
            'created_by'          => auth()->id(),
        ]);

        return response()->json([
            'message'       => 'User created. Share the temporary password securely. They must reset it on first login.',
            'user'          => $this->formatUser($user),
            'temp_password' => $tempPassword,  // shown ONCE — store it now
        ], 201);
    }

    /* ── Activate / Deactivate ───────────────────────────────── */

    public function toggleActive(User $user): JsonResponse
    {
        $this->requiresManage();
        $this->assertSubRole($user);

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
        $this->assertSubRole($user);

        $tempPassword = Str::random(6) . rand(10, 99) . '!';

        $user->update([
            'password'            => Hash::make($tempPassword),
            'must_reset_password' => true,
        ]);

        return response()->json([
            'message'       => 'Temporary password reset. Share it securely.',
            'temp_password' => $tempPassword,
        ]);
    }

    /* ── Delete user ─────────────────────────────────────────── */

    public function destroy(User $user): JsonResponse
    {
        $this->requiresManage();
        $this->assertSubRole($user);

        $user->forceDelete();

        return response()->json(['message' => 'User removed.']);
    }

    /* ── All-scope permissions matrix (admin + company + gym) ── */

    public function permissions(): JsonResponse
    {
        // ── Admin scope ───────────────────────────────────────────
        $adminPerms = Permission::where('scope', 'admin')
            ->orderBy('group_name')->orderBy('label')->get();

        $adminRoles = Role::where('scope', 'admin')
            ->where('name', '!=', 'fitaccess_admin')
            ->orderBy('created_at')
            ->get(['name', 'label']);

        $adminRoleNames  = $adminRoles->pluck('name')->toArray();
        $adminRolePerms  = RolePermission::whereIn('role', $adminRoleNames)->get()->groupBy('role');

        // ── Company scope ─────────────────────────────────────────
        $companyPerms = Permission::where('scope', 'company')
            ->orderBy('group_name')->orderBy('label')->get();

        // Exactly 3 canonical company roles shown in the permissions matrix
        $companyRoles     = ['co_hr', 'company_finance', 'company_ceo'];
        $companyRolePerms = RolePermission::whereIn('role', $companyRoles)->get()->groupBy('role');

        // ── Gym scope ─────────────────────────────────────────────
        $gymPerms = Permission::where('scope', 'gym')
            ->orderBy('group_name')->orderBy('label')->get();

        $gymRoles     = User::GYM_SUB_ROLES;
        $gymRolePerms = RolePermission::whereIn('role', $gymRoles)->get()->groupBy('role');

        $roleLabel = fn(string $name): string =>
            match ($name) {
                'co_hr', 'company_hr', 'gym_hr'                    => 'HR',
                'co_executive', 'company_ceo', 'gym_executive'      => 'Executive',
                'co_finance', 'company_finance', 'gym_finance'      => 'Finance',
                default => ucwords(str_replace(['admin_', 'co_', 'gym_', 'company_', '_'], ['', '', '', '', ' '], $name)),
            };

        return response()->json([
            'admin' => [
                'permissions'      => $adminPerms,
                'roles'            => $adminRoles->map(fn($r) => [
                    'key'   => $r->name,
                    'label' => $r->label ?? $roleLabel($r->name),
                ])->values(),
                'role_permissions' => $adminRolePerms,
            ],
            'company' => [
                'permissions'      => $companyPerms,
                'roles'            => array_map(fn($r) => ['key' => $r, 'label' => $roleLabel($r)], $companyRoles),
                'role_permissions' => $companyRolePerms,
            ],
            'gym' => [
                'permissions'      => $gymPerms,
                'roles'            => array_map(fn($r) => ['key' => $r, 'label' => $roleLabel($r)], $gymRoles),
                'role_permissions' => $gymRolePerms,
            ],
        ]);
    }

    /* ── Update permissions for any sub-role ────────────────── */

    public function updateRolePermissions(Request $request, string $role): JsonResponse
    {
        if (!auth()->user()->hasPermission('permissions.manage')) {
            abort(403, 'Only admins with permissions.manage can update role permissions.');
        }

        $allEditableRoles = array_merge(
            User::dynamicAdminSubRoles(),
            User::COMPANY_SUB_ROLES,
            User::GYM_SUB_ROLES,
        );

        if (!in_array($role, $allEditableRoles)) {
            abort(422, 'Only sub-role permissions can be edited here.');
        }

        $request->validate(['permissions' => 'required|array']);

        RolePermission::where('role', $role)->delete();
        foreach ($request->permissions as $perm) {
            RolePermission::create(['role' => $role, 'permission_name' => $perm]);
        }

        return response()->json(['message' => "Permissions updated for role: {$role}"]);
    }

    /* ── Update user-specific overrides ────────────────────── */

    public function updateUserPermissions(Request $request, User $user): JsonResponse
    {
        if (!auth()->user()->hasPermission('permissions.manage')) {
            abort(403);
        }
        $this->assertSubRole($user);

        $request->validate([
            'overrides'         => 'required|array',
            'overrides.*.name'  => 'required|string',
            'overrides.*.granted' => 'required|boolean',
        ]);

        // Replace all overrides for this user
        UserPermission::where('user_id', $user->id)->delete();
        foreach ($request->overrides as $o) {
            UserPermission::create([
                'user_id'         => $user->id,
                'permission_name' => $o['name'],
                'granted'         => $o['granted'],
            ]);
        }

        return response()->json(['message' => 'User permissions updated.']);
    }

    /* ── Helpers ─────────────────────────────────────────────── */

    private function assertSubRole(User $user): void
    {
        if (!in_array($user->role, User::ADMIN_SUB_ROLES)) {
            abort(403, 'Cannot manage this user type here.');
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
            'deleted_at'          => $u->deleted_at?->toDateString(),
        ];
    }
}
