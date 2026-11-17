<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add plans permissions (safe to run multiple times — insertOrIgnore)
        DB::table('permissions')->insertOrIgnore([
            [
                'name'        => 'plans.view',
                'label'       => 'View Plans',
                'group_name'  => 'Plans',
                'scope'       => 'admin',
                'description' => 'View membership plans',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'name'        => 'plans.manage',
                'label'       => 'Manage Plans',
                'group_name'  => 'Plans',
                'scope'       => 'admin',
                'description' => 'Create, edit and delete membership plans',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ]);

        // Assign to admin_support (view only) and admin_finance (none by default)
        // fitaccess_admin bypasses permission checks entirely so no row needed
        DB::table('role_permissions')->insertOrIgnore([
            ['role' => 'admin_support', 'permission_name' => 'plans.view',   'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->whereIn('permission_name', ['plans.view', 'plans.manage'])
            ->delete();

        DB::table('permissions')
            ->whereIn('name', ['plans.view', 'plans.manage'])
            ->delete();
    }
};
