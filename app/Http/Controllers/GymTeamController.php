<?php

namespace App\Http\Controllers;

use App\Models\Gym;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GymTeamController extends Controller
{
    private function getMyGym(): Gym
    {
        $gym = Gym::forPartner(auth()->user());
        if (!$gym) abort(403, 'No gym linked to this account.');
        return $gym;
    }

    private function requiresManage(): void
    {
        if (!auth()->user()->hasPermission('gym.team.view')) {
            abort(403, 'You do not have permission to manage gym staff.');
        }
    }

    /* ── List gym staff ──────────────────────────────────────── */

    public function index(): JsonResponse
    {
        $this->requiresManage();
        $gym = $this->getMyGym();

        $users = User::whereIn('role', User::GYM_SUB_ROLES)
            ->where('gym_id', $gym->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($u) => $this->formatUser($u));

        return response()->json($users);
    }

    /* ── Create gym staff ────────────────────────────────────── */

    public function store(Request $request): JsonResponse
    {
        $this->requiresManage();
        $gym        = $this->getMyGym();
        $validRoles = User::GYM_SUB_ROLES;

        $request->validate([
            'name'  => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'role'  => ['required', 'string', 'in:' . implode(',', $validRoles)],
        ]);

        $tempPassword = Str::password(14);

        $user = User::create([
            'name'                => $request->name,
            'email'               => $request->email,
            'password'            => Hash::make($tempPassword),
            'role'                => $request->role,
            'is_active'           => false,
            'must_reset_password' => true,
            'created_by'          => auth()->id(),
            'gym_id'              => $gym->id,
        ]);

        return response()->json([
            'message'       => 'Staff member created. Activate after sharing credentials.',
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
            'message' => $user->is_active ? 'Staff activated.' : 'Staff deactivated.',
            'user'    => $this->formatUser($user->fresh()),
        ]);
    }

    /* ── Regenerate temp password ────────────────────────────── */

    public function resetTemp(User $user): JsonResponse
    {
        $this->requiresManage();
        $this->assertOwnership($user);

        $tempPassword = Str::password(14);
        $user->update(['password' => Hash::make($tempPassword), 'must_reset_password' => true]);

        return response()->json(['message' => 'Password reset.', 'temp_password' => $tempPassword]);
    }

    /* ── Delete staff ────────────────────────────────────────── */

    public function destroy(User $user): JsonResponse
    {
        $this->requiresManage();
        $this->assertOwnership($user);
        $user->delete();
        return response()->json(['message' => 'Staff member removed.']);
    }

    /* ── Gym-scope permissions ───────────────────────────────── */

    public function permissions(): JsonResponse
    {
        $this->requiresManage();

        $allPerms  = Permission::where('scope', 'gym')->orderBy('group_name')->orderBy('label')->get();
        $rolePerms = RolePermission::whereIn('role', array_merge(['gym_partner'], User::GYM_SUB_ROLES))
            ->get()->groupBy('role');

        return response()->json(['permissions' => $allPerms, 'role_permissions' => $rolePerms]);
    }

    public function updateRolePermissions(Request $request, string $role): JsonResponse
    {
        $this->requiresManage();
        if (!in_array($role, User::GYM_SUB_ROLES)) {
            abort(422, 'Only sub-role permissions can be edited.');
        }

        $request->validate(['permissions' => 'required|array']);

        RolePermission::where('role', $role)->delete();
        foreach ($request->permissions as $perm) {
            RolePermission::create(['role' => $role, 'permission_name' => $perm]);
        }

        return response()->json(['message' => 'Staff permissions updated.']);
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

        return response()->json(['message' => 'Staff permissions updated.']);
    }

    /* ── Helpers ─────────────────────────────────────────────── */

    private function assertOwnership(User $user): void
    {
        $gym = $this->getMyGym();
        if ($user->gym_id !== $gym->id || !in_array($user->role, User::GYM_SUB_ROLES)) abort(403);
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
