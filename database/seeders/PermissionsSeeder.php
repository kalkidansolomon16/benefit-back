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
            // ── Admin scope: Navigation ──────────────────────────
            ['name' => 'dashboard.view',         'label' => 'View Dashboard',           'group_name' => 'Dashboard',     'scope' => 'admin'],
            ['name' => 'companies.view',          'label' => 'View Companies',           'group_name' => 'Companies',     'scope' => 'admin'],
            ['name' => 'gyms.view',               'label' => 'View Gyms',                'group_name' => 'Gyms',          'scope' => 'admin'],
            ['name' => 'employees.view',          'label' => 'View All Employees',       'group_name' => 'Employees',     'scope' => 'admin'],
            ['name' => 'employee_approvals.view', 'label' => 'View Employee Approvals',  'group_name' => 'Employees',     'scope' => 'admin'],
            ['name' => 'plans.view',              'label' => 'View Plans',               'group_name' => 'Plans',         'scope' => 'admin'],
            ['name' => 'memberships.view',        'label' => 'View Memberships',         'group_name' => 'Memberships',   'scope' => 'admin'],
            ['name' => 'reports.view',            'label' => 'View Reports',             'group_name' => 'Reports',       'scope' => 'admin'],
            ['name' => 'activity_log.view',       'label' => 'View Activity Log',        'group_name' => 'Reports',       'scope' => 'admin'],
            ['name' => 'billing.view',            'label' => 'View Invoices',            'group_name' => 'Billing',       'scope' => 'admin'],
            ['name' => 'payment_methods.view',    'label' => 'View Payment Methods',     'group_name' => 'Billing',       'scope' => 'admin'],
            ['name' => 'team.view',               'label' => 'View Team',                'group_name' => 'Team & Access', 'scope' => 'admin'],
            ['name' => 'permissions.view',        'label' => 'View Permissions',         'group_name' => 'Team & Access', 'scope' => 'admin'],

            // ── Admin scope: Companies CRUD ───────────────────────
            ['name' => 'companies.create',        'label' => 'Create Company',           'group_name' => 'Companies',     'scope' => 'admin'],
            ['name' => 'companies.edit',          'label' => 'Edit Company',             'group_name' => 'Companies',     'scope' => 'admin'],
            ['name' => 'companies.delete',        'label' => 'Delete Company',           'group_name' => 'Companies',     'scope' => 'admin'],

            // ── Admin scope: Gyms CRUD ────────────────────────────
            ['name' => 'gyms.create',             'label' => 'Create Gym',               'group_name' => 'Gyms',          'scope' => 'admin'],
            ['name' => 'gyms.edit',               'label' => 'Edit Gym',                 'group_name' => 'Gyms',          'scope' => 'admin'],
            ['name' => 'gyms.delete',             'label' => 'Delete Gym',               'group_name' => 'Gyms',          'scope' => 'admin'],
            ['name' => 'gyms.upgrade_approve',    'label' => 'Approve Gym Upgrade',      'group_name' => 'Gyms',          'scope' => 'admin'],
            ['name' => 'gyms.upgrade_reject',     'label' => 'Reject Gym Upgrade',       'group_name' => 'Gyms',          'scope' => 'admin'],

            // ── Admin scope: Employees CRUD ───────────────────────
            ['name' => 'employees.create',        'label' => 'Create Employee',          'group_name' => 'Employees',     'scope' => 'admin'],
            ['name' => 'employees.edit',          'label' => 'Edit Employee',            'group_name' => 'Employees',     'scope' => 'admin'],
            ['name' => 'employees.delete',        'label' => 'Delete Employee',          'group_name' => 'Employees',     'scope' => 'admin'],
            ['name' => 'employees.approve',       'label' => 'Approve Employee',         'group_name' => 'Employees',     'scope' => 'admin'],
            ['name' => 'employees.ban',           'label' => 'Ban Employee',             'group_name' => 'Employees',     'scope' => 'admin'],

            // ── Admin scope: Plans CRUD ───────────────────────────
            ['name' => 'plans.create',            'label' => 'Create Plan',              'group_name' => 'Plans',         'scope' => 'admin'],
            ['name' => 'plans.edit',              'label' => 'Edit Plan',                'group_name' => 'Plans',         'scope' => 'admin'],
            ['name' => 'plans.delete',            'label' => 'Delete Plan',              'group_name' => 'Plans',         'scope' => 'admin'],
            ['name' => 'plans.toggle_active',     'label' => 'Toggle Plan Active',       'group_name' => 'Plans',         'scope' => 'admin'],

            // ── Admin scope: Memberships CRUD ─────────────────────
            ['name' => 'memberships.create',      'label' => 'Assign Membership',        'group_name' => 'Memberships',   'scope' => 'admin'],
            ['name' => 'memberships.edit',        'label' => 'Edit Membership',          'group_name' => 'Memberships',   'scope' => 'admin'],
            ['name' => 'memberships.delete',      'label' => 'Remove Membership',        'group_name' => 'Memberships',   'scope' => 'admin'],
            ['name' => 'memberships.suspend',     'label' => 'Suspend Membership',       'group_name' => 'Memberships',   'scope' => 'admin'],

            // ── Admin scope: Billing CRUD ─────────────────────────
            ['name' => 'billing.create',          'label' => 'Create Invoice',           'group_name' => 'Billing',       'scope' => 'admin'],
            ['name' => 'billing.delete',          'label' => 'Delete Invoice',           'group_name' => 'Billing',       'scope' => 'admin'],
            ['name' => 'billing.send',            'label' => 'Send Invoice',             'group_name' => 'Billing',       'scope' => 'admin'],
            ['name' => 'payments.verify',         'label' => 'Verify Payment',           'group_name' => 'Billing',       'scope' => 'admin'],
            ['name' => 'payments.reject',         'label' => 'Reject Payment',           'group_name' => 'Billing',       'scope' => 'admin'],
            ['name' => 'negotiations.approve',    'label' => 'Approve Negotiation',      'group_name' => 'Billing',       'scope' => 'admin'],
            ['name' => 'negotiations.reject',     'label' => 'Reject Negotiation',       'group_name' => 'Billing',       'scope' => 'admin'],
            ['name' => 'payment_methods.create',  'label' => 'Create Payment Method',    'group_name' => 'Billing',       'scope' => 'admin'],
            ['name' => 'payment_methods.edit',    'label' => 'Edit Payment Method',      'group_name' => 'Billing',       'scope' => 'admin'],
            ['name' => 'payment_methods.delete',  'label' => 'Delete Payment Method',    'group_name' => 'Billing',       'scope' => 'admin'],
            ['name' => 'payment_methods.toggle',  'label' => 'Toggle Payment Method',    'group_name' => 'Billing',       'scope' => 'admin'],

            // ── Admin scope: Team CRUD ───────────────────────────
            ['name' => 'team.create',             'label' => 'Add Team Member',          'group_name' => 'Team & Access', 'scope' => 'admin'],
            ['name' => 'team.edit',               'label' => 'Edit Team Member',         'group_name' => 'Team & Access', 'scope' => 'admin'],
            ['name' => 'team.delete',             'label' => 'Remove Team Member',       'group_name' => 'Team & Access', 'scope' => 'admin'],
            ['name' => 'permissions.manage',      'label' => 'Manage Permissions',       'group_name' => 'Team & Access', 'scope' => 'admin'],

            // ── Company scope: Navigation ─────────────────────────
            ['name' => 'co.dashboard.view',       'label' => 'View Dashboard',           'group_name' => 'Dashboard',     'scope' => 'company'],
            ['name' => 'co.employees.view',       'label' => 'View Employees',           'group_name' => 'Employees',     'scope' => 'company'],
            ['name' => 'co.employees.register',   'label' => 'Register Employee (Nav)',  'group_name' => 'Employees',     'scope' => 'company'],
            ['name' => 'co.billing.view',         'label' => 'View Billing',             'group_name' => 'Billing',       'scope' => 'company'],
            ['name' => 'co.reports.view',         'label' => 'View Reports',             'group_name' => 'Reports',       'scope' => 'company'],
            ['name' => 'co.team.view',            'label' => 'View Team',                'group_name' => 'Team & Access', 'scope' => 'company'],

            // ── Company scope: Employees CRUD ─────────────────────
            ['name' => 'co.employees.create',     'label' => 'Register New Employee',    'group_name' => 'Employees',     'scope' => 'company'],
            ['name' => 'co.employees.approve',    'label' => 'Approve Employee',         'group_name' => 'Employees',     'scope' => 'company'],
            ['name' => 'co.employees.reject',     'label' => 'Reject Employee',          'group_name' => 'Employees',     'scope' => 'company'],
            ['name' => 'co.employees.ban',        'label' => 'Ban Employee',             'group_name' => 'Employees',     'scope' => 'company'],

            // ── Company scope: Billing CRUD ───────────────────────
            ['name' => 'co.billing.pay',          'label' => 'Submit Payment',           'group_name' => 'Billing',       'scope' => 'company'],
            ['name' => 'co.billing.negotiate',    'label' => 'Request Extension',        'group_name' => 'Billing',       'scope' => 'company'],

            // ── Company scope: Team CRUD ──────────────────────────
            ['name' => 'co.team.create',          'label' => 'Add Team Member',          'group_name' => 'Team & Access', 'scope' => 'company'],
            ['name' => 'co.team.edit',            'label' => 'Edit Team Member',         'group_name' => 'Team & Access', 'scope' => 'company'],
            ['name' => 'co.team.delete',          'label' => 'Remove Team Member',       'group_name' => 'Team & Access', 'scope' => 'company'],

            // ── Gym scope: Navigation ─────────────────────────────
            ['name' => 'gym.dashboard.view',      'label' => 'View Dashboard',           'group_name' => 'Dashboard',     'scope' => 'gym'],
            ['name' => 'gym.checkins.view',       'label' => 'View Check-ins',           'group_name' => 'Check-ins',     'scope' => 'gym'],
            ['name' => 'gym.facility.view',       'label' => 'View Facility Info',       'group_name' => 'Facility',      'scope' => 'gym'],
            ['name' => 'gym.team.view',           'label' => 'View Staff',               'group_name' => 'Team & Access', 'scope' => 'gym'],
            ['name' => 'gym.members.view',        'label' => 'View Members',             'group_name' => 'Members',       'scope' => 'gym'],
            ['name' => 'gym.reports.view',        'label' => 'View Reports',             'group_name' => 'Reports',       'scope' => 'gym'],

            // ── Gym scope: Check-ins CRUD ─────────────────────────
            ['name' => 'gym.checkins.create',     'label' => 'Record Check-in',          'group_name' => 'Check-ins',     'scope' => 'gym'],

            // ── Gym scope: Facility CRUD ──────────────────────────
            ['name' => 'gym.profile.edit',        'label' => 'Edit Gym Profile',         'group_name' => 'Facility',      'scope' => 'gym'],
            ['name' => 'gym.upgrade.request',     'label' => 'Request Tier Upgrade',     'group_name' => 'Facility',      'scope' => 'gym'],

            // ── Gym scope: Team CRUD ──────────────────────────────
            ['name' => 'gym.team.create',         'label' => 'Add Staff Member',         'group_name' => 'Team & Access', 'scope' => 'gym'],
            ['name' => 'gym.team.edit',           'label' => 'Edit Staff Member',        'group_name' => 'Team & Access', 'scope' => 'gym'],
            ['name' => 'gym.team.delete',         'label' => 'Remove Staff Member',      'group_name' => 'Team & Access', 'scope' => 'gym'],
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p['name']], $p);
        }

        /* ── 2. Define default role → permission mappings ──────── */

        $roleDefaults = [
            'fitaccess_admin' => [
                // Navigation
                'dashboard.view', 'companies.view', 'gyms.view',
                'employees.view', 'employee_approvals.view', 'plans.view',
                'memberships.view', 'reports.view', 'activity_log.view',
                'billing.view', 'payment_methods.view', 'team.view', 'permissions.view',
                // Companies CRUD
                'companies.create', 'companies.edit', 'companies.delete',
                // Gyms CRUD
                'gyms.create', 'gyms.edit', 'gyms.delete',
                'gyms.upgrade_approve', 'gyms.upgrade_reject',
                // Employees CRUD
                'employees.create', 'employees.edit', 'employees.delete',
                'employees.approve', 'employees.ban',
                // Plans CRUD
                'plans.create', 'plans.edit', 'plans.delete', 'plans.toggle_active',
                // Memberships CRUD
                'memberships.create', 'memberships.edit', 'memberships.delete', 'memberships.suspend',
                // Billing CRUD
                'billing.create', 'billing.delete', 'billing.send',
                'payments.verify', 'payments.reject',
                'negotiations.approve', 'negotiations.reject',
                'payment_methods.create', 'payment_methods.edit',
                'payment_methods.delete', 'payment_methods.toggle',
                // Team CRUD
                'team.create', 'team.edit', 'team.delete', 'permissions.manage',
            ],
            'admin_finance' => [
                'dashboard.view', 'billing.view', 'payment_methods.view', 'reports.view',
                'billing.create', 'billing.delete', 'billing.send',
                'payments.verify', 'payments.reject',
                'negotiations.approve', 'negotiations.reject',
                'payment_methods.create', 'payment_methods.edit',
                'payment_methods.delete', 'payment_methods.toggle',
            ],
            'admin_support' => [
                'dashboard.view', 'companies.view', 'gyms.view',
                'employees.view', 'employee_approvals.view',
                'memberships.view', 'reports.view', 'activity_log.view',
            ],
            'company_hr' => [
                'co.dashboard.view',
                'co.employees.view', 'co.employees.register',
                'co.billing.view', 'co.reports.view', 'co.team.view',
                'co.employees.create', 'co.employees.approve',
                'co.employees.reject', 'co.employees.ban',
                'co.billing.pay', 'co.billing.negotiate',
                'co.team.create', 'co.team.edit', 'co.team.delete',
            ],
            'company_finance' => [
                'co.dashboard.view', 'co.billing.view', 'co.reports.view',
                'co.billing.pay', 'co.billing.negotiate',
            ],
            'company_ceo' => [
                'co.dashboard.view', 'co.employees.view',
                'co.billing.view', 'co.reports.view',
            ],
            // Sub-roles assigned to company team members
            'co_hr' => [
                'co.dashboard.view',
                'co.employees.view', 'co.employees.register',
                'co.billing.view', 'co.reports.view', 'co.team.view',
                'co.employees.create', 'co.employees.approve',
                'co.employees.reject', 'co.employees.ban',
                'co.billing.pay', 'co.billing.negotiate',
                'co.team.create', 'co.team.edit', 'co.team.delete',
            ],
            'co_executive' => [
                'co.dashboard.view', 'co.employees.view',
                'co.billing.view', 'co.reports.view',
            ],
            'co_finance' => [
                'co.dashboard.view', 'co.billing.view', 'co.reports.view',
                'co.billing.pay', 'co.billing.negotiate',
            ],
            'gym_partner' => [
                'gym.dashboard.view', 'gym.checkins.view', 'gym.facility.view',
                'gym.team.view', 'gym.members.view', 'gym.reports.view',
                'gym.checkins.create',
                'gym.profile.edit', 'gym.upgrade.request',
                'gym.team.create', 'gym.team.edit', 'gym.team.delete',
            ],
            // Sub-roles assigned to gym staff members
            'gym_manager' => [
                'gym.dashboard.view', 'gym.checkins.view', 'gym.facility.view',
                'gym.team.view', 'gym.members.view', 'gym.reports.view',
                'gym.checkins.create',
                'gym.team.create', 'gym.team.edit', 'gym.team.delete',
            ],
            'gym_receptionist' => [
                'gym.dashboard.view', 'gym.checkins.view',
                'gym.checkins.create', 'gym.members.view',
            ],
            'gym_trainer' => [
                'gym.dashboard.view', 'gym.checkins.view', 'gym.members.view',
            ],
            'gym_staff' => [
                'gym.dashboard.view', 'gym.checkins.view',
                'gym.checkins.create', 'gym.members.view',
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
