<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Gym;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RolesController extends Controller
{
    /* ════════════════════════════════════════════════════════
     |  ADMIN ROLES
     ╚════════════════════════════════════════════════════════ */

    public function adminIndex(): JsonResponse
    {
        $roles = Role::where('scope', 'admin')
            ->orderBy('is_system', 'desc')
            ->orderBy('created_at')
            ->get()
            ->map(fn($r) => $this->format($r));

        return response()->json($roles);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $request->validate(['label' => 'required|string|max:80']);

        $slug = Role::makeSlug($request->label, 'admin');

        $role = Role::create([
            'name'       => $slug,
            'label'      => $request->label,
            'scope'      => 'admin',
            'is_system'  => false,
            'created_by' => auth()->id(),
        ]);

        return response()->json(['message' => 'Role created.', 'role' => $this->format($role)], 201);
    }

    public function adminDestroy(Role $role): JsonResponse
    {
        $this->assertDeletable($role, 'admin');

        RolePermission::where('role', $role->name)->delete();
        $role->delete();

        return response()->json(['message' => 'Role deleted.']);
    }

    /* ════════════════════════════════════════════════════════
     |  COMPANY ROLES
     ╚════════════════════════════════════════════════════════ */

    public function companyIndex(): JsonResponse
    {
        $company = $this->getMyCompany();

        // System company roles + custom roles for this company
        $roles = Role::where('scope', 'company')
            ->where(fn($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))
            ->orderBy('is_system', 'desc')
            ->orderBy('created_at')
            ->get()
            ->map(fn($r) => $this->format($r));

        return response()->json($roles);
    }

    public function companyStore(Request $request): JsonResponse
    {
        $company = $this->getMyCompany();
        $request->validate(['label' => 'required|string|max:80']);

        $slug = Role::makeSlug($request->label, 'co_' . $company->id);

        $role = Role::create([
            'name'       => $slug,
            'label'      => $request->label,
            'scope'      => 'company',
            'company_id' => $company->id,
            'is_system'  => false,
            'created_by' => auth()->id(),
        ]);

        return response()->json(['message' => 'Role created.', 'role' => $this->format($role)], 201);
    }

    public function companyDestroy(Role $role): JsonResponse
    {
        $company = $this->getMyCompany();
        if ($role->company_id !== $company->id) abort(403);
        $this->assertDeletable($role, 'company');

        RolePermission::where('role', $role->name)->delete();
        $role->delete();

        return response()->json(['message' => 'Role deleted.']);
    }

    /* ════════════════════════════════════════════════════════
     |  GYM ROLES
     ╚════════════════════════════════════════════════════════ */

    public function gymIndex(): JsonResponse
    {
        $gym = $this->getMyGym();

        $roles = Role::where('scope', 'gym')
            ->where(fn($q) => $q->whereNull('gym_id')->orWhere('gym_id', $gym->id))
            ->orderBy('is_system', 'desc')
            ->orderBy('created_at')
            ->get()
            ->map(fn($r) => $this->format($r));

        return response()->json($roles);
    }

    public function gymStore(Request $request): JsonResponse
    {
        $gym = $this->getMyGym();
        $request->validate(['label' => 'required|string|max:80']);

        $slug = Role::makeSlug($request->label, 'gym_' . $gym->id);

        $role = Role::create([
            'name'       => $slug,
            'label'      => $request->label,
            'scope'      => 'gym',
            'gym_id'     => $gym->id,
            'is_system'  => false,
            'created_by' => auth()->id(),
        ]);

        return response()->json(['message' => 'Role created.', 'role' => $this->format($role)], 201);
    }

    public function gymDestroy(Role $role): JsonResponse
    {
        $gym = $this->getMyGym();
        if ($role->gym_id !== $gym->id) abort(403);
        $this->assertDeletable($role, 'gym');

        RolePermission::where('role', $role->name)->delete();
        $role->delete();

        return response()->json(['message' => 'Role deleted.']);
    }

    /* ════════════════════════════════════════════════════════
     |  HELPERS
     ╚════════════════════════════════════════════════════════ */

    private function format(Role $r): array
    {
        return [
            'id'        => $r->id,
            'name'      => $r->name,
            'label'     => $r->label,
            'scope'     => $r->scope,
            'is_system' => $r->is_system,
        ];
    }

    private function assertDeletable(Role $role, string $scope): void
    {
        if ($role->scope !== $scope) abort(403);
        if ($role->is_system) abort(422, 'System roles cannot be deleted.');
    }

    private function getMyCompany(): Company
    {
        $user    = auth()->user();
        if ($user->company_id) return Company::findOrFail($user->company_id);
        $company = Company::where('contact_email', $user->email)->first();
        if (!$company) abort(403, 'No company linked to this account.');
        return $company;
    }

    private function getMyGym(): Gym
    {
        $user = auth()->user();
        if ($user->gym_id) return Gym::findOrFail($user->gym_id);
        $gym  = Gym::where('owner_user_id', $user->id)
            ->orWhere('contact_email', $user->email)
            ->first();
        if (!$gym) abort(403, 'No gym linked to this account.');
        return $gym;
    }
}
