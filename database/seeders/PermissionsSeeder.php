<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\RolePermission;
use Illuminate\Database\Seeder;

class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        /* ── 1. Define all permissions ─────────────────────────── */

        $permissions = [
            // ── Admin scope ──────────────────────────────────────
            ['name' => 'dashboard.view',          'label' => 'View Dashboard',         'group_name' => 'Dashboard',        'scope' => 'admin'],
            ['name' => 'companies.view',           'label' => 'View Companies',         'group_name' => 'Companies',        'scope' => 'admin'],
            ['name' => 'companies.manage',         'label' => 'Manage Companies',       'group_name' => 'Companies',        'scope' => 'admin'],
            ['name' => 'employees.view',           'label' => 'View Employees',         'group_name' => 'Employees',        'scope' => 'admin'],
            ['name' => 'employees.manage',         'label' => 'Manage Employees',       'group_name' => 'Employees',        'scope' => 'admin'],
            ['name' => 'gyms.view',                'label' => 'View Gyms',              'group_name' => 'Gyms',             'scope' => 'admin'],
            ['name' => 'gyms.manage',              'label' => 'Manage Gyms',            'group_name' => 'Gyms',             'scope' => 'admin'],
            ['name' => 'billing.view',             'label' => 'View Billing',           'group_name' => 'Billing',          'scope' => 'admin'],
            ['name' => 'billing.manage',           'label' => 'Manage Billing',         'group_name' => 'Billing',          'scope' => 'admin'],
            ['name' => 'payments.verify',          'label' => 'Verify Payments',        'group_name' => 'Billing',          'scope' => 'admin'],
            ['name' => 'payments.reject',          'label' => 'Reject Payments',        'group_name' => 'Billing',          'scope' => 'admin'],
            ['name' => 'negotiations.manage',      'label' => 'Manage Negotiations',    'group_name' => 'Billing',          'scope' => 'admin'],
            ['name' => 'payment_methods.manage',   'label' => 'Manage Payment Methods', 'group_name' => 'Billing',          'scope' => 'admin'],
            ['name' => 'reports.view',             'label' => 'View Reports',           'group_name' => 'Reports',          'scope' => 'admin'],
            ['name' => 'activity_log.view',        'label' => 'View Activity Log',      'group_name' => 'Reports',          'scope' => 'admin'],
            ['name' => 'team.manage',              'label' => 'Manage Team',            'group_name' => 'Team & Access',    'scope' => 'admin'],
            ['name' => 'permissions.manage',       'label' => 'Manage Permissions',     'group_name' => 'Team & Access',    'scope' => 'admin'],

            // ── Company scope ─────────────────────────────────────
            ['name' => 'co.dashboard.view',        'label' => 'View Dashboard',         'group_name' => 'Dashboard',        'scope' => 'company'],
            ['name' => 'co.employees.view',        'label' => 'View Employees',         'group_name' => 'Employees',        'scope' => 'company'],
            ['name' => 'co.employees.manage',      'label' => 'Manage Employees',       'group_name' => 'Employees',        'scope' => 'company'],
            ['name' => 'co.billing.view',          'label' => 'View Billing',           'group_name' => 'Billing',          'scope' => 'company'],
            ['name' => 'co.billing.pay',           'label' => 'Submit Payments',        'group_name' => 'Billing',          'scope' => 'company'],
            ['name' => 'co.billing.negotiate',     'label' => 'Request Extensions',     'group_name' => 'Billing',          'scope' => 'company'],
            ['name' => 'co.reports.view',          'label' => 'View Reports',           'group_name' => 'Reports',          'scope' => 'company'],
            ['name' => 'co.team.manage',           'label' => 'Manage Team',            'group_name' => 'Team & Access',    'scope' => 'company'],

            // ── Gym scope ─────────────────────────────────────────
            ['name' => 'gym.dashboard.view',       'label' => 'View Dashboard',         'group_name' => 'Dashboard',        'scope' => 'gym'],
            ['name' => 'gym.checkins.view',        'label' => 'View Check-ins',         'group_name' => 'Check-ins',        'scope' => 'gym'],
            ['name' => 'gym.checkins.manage',      'label' => 'Record Check-ins',       'group_name' => 'Check-ins',        'scope' => 'gym'],
            ['name' => 'gym.members.view',         'label' => 'View Members',           'group_name' => 'Members',          'scope' => 'gym'],
            ['name' => 'gym.profile.manage',       'label' => 'Manage Gym Profile',     'group_name' => 'Gym',              'scope' => 'gym'],
            ['name' => 'gym.reports.view',         'label' => 'View Reports',           'group_name' => 'Reports',          'scope' => 'gym'],
            ['name' => 'gym.team.manage',          'label' => 'Manage Staff',           'group_name' => 'Team & Access',    'scope' => 'gym'],
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p['name']], $p);
        }

        /* ── 2. Define default role → permission mappings ──────── */

        $roleDefaults = [
            'fitaccess_admin' => [
                'dashboard.view', 'companies.view', 'companies.manage',
                'employees.view', 'employees.manage', 'gyms.view', 'gyms.manage',
                'billing.view', 'billing.manage', 'payments.verify', 'payments.reject',
                'negotiations.manage', 'payment_methods.manage',
                'reports.view', 'activity_log.view',
                'team.manage', 'permissions.manage',
            ],
            'admin_finance' => [
                'dashboard.view', 'billing.view', 'billing.manage',
                'payments.verify', 'payments.reject', 'negotiations.manage',
                'payment_methods.manage', 'reports.view',
            ],
            'admin_support' => [
                'dashboard.view', 'companies.view', 'employees.view',
                'gyms.view', 'reports.view', 'activity_log.view',
            ],
            'company_hr' => [
                'co.dashboard.view', 'co.employees.view', 'co.employees.manage',
                'co.billing.view', 'co.billing.pay', 'co.billing.negotiate',
                'co.reports.view', 'co.team.manage',
            ],
            'company_finance' => [
                'co.dashboard.view', 'co.billing.view', 'co.billing.pay',
                'co.billing.negotiate', 'co.reports.view',
            ],
            'company_ceo' => [
                'co.dashboard.view', 'co.employees.view',
                'co.billing.view', 'co.reports.view',
            ],
            'gym_partner' => [
                'gym.dashboard.view', 'gym.checkins.view', 'gym.checkins.manage',
                'gym.members.view', 'gym.profile.manage', 'gym.reports.view',
                'gym.team.manage',
            ],
            'gym_staff' => [
                'gym.dashboard.view', 'gym.checkins.view',
                'gym.checkins.manage', 'gym.members.view',
            ],
        ];

        // Wipe existing role defaults then re-seed (idempotent)
        foreach ($roleDefaults as $role => $perms) {
            RolePermission::where('role', $role)->delete();
            foreach ($perms as $perm) {
                RolePermission::create(['role' => $role, 'permission_name' => $perm]);
            }
        }
    }
}
