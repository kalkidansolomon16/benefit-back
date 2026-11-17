<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Old coarse-grained permissions replaced by granular ones
    private array $removed = [
        'companies.manage',
        'employees.manage',
        'gyms.manage',
        'billing.manage',
        'negotiations.manage',
        'payment_methods.manage',
        'team.manage',
        'gym.profile.manage',
        'gym.team.manage',
        'gym.checkins.manage',
        'co.employees.manage',
        'co.team.manage',
    ];

    public function up(): void
    {
        // Remove old coarse permissions and all role/user assignments referencing them
        DB::table('role_permissions')->whereIn('permission_name', $this->removed)->delete();
        DB::table('user_permissions')->whereIn('permission_name', $this->removed)->delete();
        DB::table('permissions')->whereIn('name', $this->removed)->delete();

        // Re-seed granular permissions and role defaults
        $this->callSeeder();
    }

    public function down(): void
    {
        // Restore old permissions (run PermissionsSeeder manually for full rollback)
        $old = [
            ['name' => 'companies.manage',       'label' => 'Manage Companies',       'group_name' => 'Companies',     'scope' => 'admin'],
            ['name' => 'employees.manage',       'label' => 'Manage Employees',       'group_name' => 'Employees',     'scope' => 'admin'],
            ['name' => 'gyms.manage',            'label' => 'Manage Gyms',            'group_name' => 'Gyms',          'scope' => 'admin'],
            ['name' => 'billing.manage',         'label' => 'Manage Billing',         'group_name' => 'Billing',       'scope' => 'admin'],
            ['name' => 'negotiations.manage',    'label' => 'Manage Negotiations',    'group_name' => 'Billing',       'scope' => 'admin'],
            ['name' => 'payment_methods.manage', 'label' => 'Manage Payment Methods', 'group_name' => 'Billing',       'scope' => 'admin'],
            ['name' => 'team.manage',            'label' => 'Manage Team',            'group_name' => 'Team & Access', 'scope' => 'admin'],
            ['name' => 'gym.profile.manage',     'label' => 'Manage Gym Profile',     'group_name' => 'Gym',           'scope' => 'gym'],
            ['name' => 'gym.team.manage',        'label' => 'Manage Staff',           'group_name' => 'Team & Access', 'scope' => 'gym'],
            ['name' => 'gym.checkins.manage',    'label' => 'Record Check-ins',       'group_name' => 'Check-ins',     'scope' => 'gym'],
            ['name' => 'co.employees.manage',    'label' => 'Manage Employees',       'group_name' => 'Employees',     'scope' => 'company'],
            ['name' => 'co.team.manage',         'label' => 'Manage Team',            'group_name' => 'Team & Access', 'scope' => 'company'],
        ];

        foreach ($old as $p) {
            DB::table('permissions')->insertOrIgnore($p);
        }
    }

    private function callSeeder(): void
    {
        $seeder = new \Database\Seeders\PermissionsSeeder();
        $seeder->run();
    }
};
