<?php

namespace App\Http\Controllers;

use App\Models\Gym;
use App\Models\Permission;
use App\Models\Role;
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
        $user = auth()->user();
        if ($user->gym_id) return Gym::findOrFail($user->gym_id);
        $gym = Gym::where('owner_user_id', $user->id)
            ->orWhere('contact_email', $user->email)
            ->first();
        if (!$gym) abort(403, 'No gym linked to this account.');
        return $gym;
    }

    private function requiresManage(): void
    {
        if (!auth()->user()->hasPermission('gym.team.manage')) {
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
        $gym = $this->getMyGym();

        $gym        = $this->getMyGym();
        $validRoles = Role::where('scope', 'gym')
            ->where(fn($q) => $q->whereNull('gym_id')->orWhere('gym_id', $gym->id))
            ->where('name', '!=', 'gym_partner')   // owner role not assignable
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
            'role'                => 'gym_staff',
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

        $tempPassword = Str::random(6) . rand(10, 99) . '!';
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
        $rolePerms = RolePermission::whereIn('role', ['gym_partner', 'gym_staff'])->get()->groupBy('role');

        return response()->json(['permissions' => $allPerms, 'role_permissions' => $rolePerms]);
    }

    public function updateRolePermissions(Request $request, string $role): JsonResponse
    {
        $this->requiresManage();
        if ($role !== 'gym_staff') abort(422, 'Only gym_staff permissions can be edited.');

        $request->validate(['permissions' => 'required|array']);

        RolePermission::where('role', 'gym_staff')->delete();
        foreach ($request->permissions as $perm) {
            RolePermission::create(['role' => 'gym_staff', 'permission_name' => $perm]);
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
