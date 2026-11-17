<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Broken access control on the admin API (OWASP API5, CWE-285).
 *
 * Before the fix any signed-in account, including a member who registered
 * through the public sign-up, could read every employee, company and invoice
 * and change or delete plans, companies and gyms.
 */
class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    /** Protected routes every signed-in account may use. */
    private const SHARED_PREFIXES = ['api/v1/auth/', 'api/v1/telegram/'];

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();
        DB::table('companies')->insert(['id' => 1, 'name' => 'Acme', 'contact_email' => 'hr@acme.test', 'contact_person' => 'A', 'contact_phone' => '1', 'address' => 'a', 'city' => 'Addis Ababa', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('gyms')->insert(['id' => 1, 'name' => 'Bole Gym', 'address' => 'a', 'city' => 'Addis Ababa', 'tier' => 'basic', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('membership_plans')->insert(['id' => 1, 'name' => 'Fit Basic', 'tier' => 'basic', 'monthly_fee_etb' => 3600, 'duration_months' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    }

    private function user(string $role, array $extra = []): User
    {
        return User::create(array_merge([
            'name'      => $role,
            'email'     => $role . '.' . uniqid() . '@example.test',
            'password'  => Hash::make('Str0ng-Passw0rd!x'),
            'role'      => $role,
            'is_active' => true,
        ], $extra));
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $this;
    }

    private function grant(string $role, array $permissions): void
    {
        foreach ($permissions as $p) {
            DB::table('role_permissions')->updateOrInsert(['role' => $role, 'permission_name' => $p], ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_every_protected_route_has_an_area_gate(): void
    {
        $ungated = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            if (!in_array('auth:sanctum', $middleware, true)) {
                continue;
            }
            if (collect(self::SHARED_PREFIXES)->contains(fn ($p) => str_starts_with($route->uri(), $p))) {
                continue;
            }
            if (!collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'area:'))) {
                $ungated[] = implode('|', $route->methods()) . ' ' . $route->uri();
            }
        }

        $this->assertSame([], $ungated, 'Protected routes without an area: gate');
    }

    public static function adminOnlyRequests(): array
    {
        return [
            'list employees'        => ['GET', '/api/v1/employees'],
            'list companies'        => ['GET', '/api/v1/companies'],
            'delete company'        => ['DELETE', '/api/v1/companies/1'],
            'delete gym'            => ['DELETE', '/api/v1/gyms/1'],
            'change plan price'     => ['PATCH', '/api/v1/membership-plans/1', ['monthly_fee_etb' => 1]],
            'delete plan'           => ['DELETE', '/api/v1/membership-plans/1'],
            'list check-ins'        => ['GET', '/api/v1/checkins'],
            'check in by FAN'       => ['POST', '/api/v1/checkins', ['fan_number' => '123', 'gym_id' => 1]],
            'list invoices'         => ['GET', '/api/v1/invoices'],
            'admin billing'         => ['GET', '/api/v1/admin/billing/invoices'],
            'admin roles'           => ['GET', '/api/v1/admin/roles'],
            'admin permissions'     => ['GET', '/api/v1/admin/team/permissions'],
            'dashboard stats'       => ['GET', '/api/v1/dashboard/stats'],
            'admin notifications'   => ['GET', '/api/v1/notifications'],
            'test email'            => ['POST', '/api/v1/admin/test-email', ['email' => 'x@example.test']],
        ];
    }

    #[DataProvider('adminOnlyRequests')]
    public function test_non_admin_accounts_are_refused_admin_routes(string $method, string $uri, array $body = []): void
    {
        $accounts = [
            $this->user('member'),
            $this->user('employee', ['company_id' => 1]),
            $this->user('company_hr', ['company_id' => 1]),
            $this->user('gym_partner', ['gym_id' => 1]),
        ];

        foreach ($accounts as $account) {
            $this->as($account)->json($method, $uri, $body)
                ->assertForbidden();
        }

        $this->assertDatabaseHas('companies', ['id' => 1]);
        $this->assertDatabaseHas('gyms', ['id' => 1]);
        $this->assertDatabaseHas('membership_plans', ['id' => 1, 'monthly_fee_etb' => 3600]);
    }

    public function test_full_admin_reaches_admin_routes(): void
    {
        $admin = $this->user('fitaccess_admin');

        $this->as($admin)->getJson('/api/v1/companies')->assertOk();
        $this->as($admin)->getJson('/api/v1/employees')->assertOk();
        $this->as($admin)->getJson('/api/v1/dashboard/stats')->assertOk();
        $this->as($admin)->patchJson('/api/v1/membership-plans/1', ['monthly_fee_etb' => 3700])->assertOk();
        $this->assertDatabaseHas('membership_plans', ['id' => 1, 'monthly_fee_etb' => 3700]);
    }

    public function test_admin_sub_roles_only_get_their_permissions(): void
    {
        $this->grant('admin_finance', ['billing.view', 'billing.create', 'payment_methods.view']);
        $this->grant('admin_support', ['companies.view', 'employees.view', 'dashboard.view']);
        $finance = $this->user('admin_finance');
        $support = $this->user('admin_support');

        // Finance: billing pages, plus the company list the billing page reads
        $this->as($finance)->getJson('/api/v1/admin/billing/invoices')->assertOk();
        $this->as($finance)->getJson('/api/v1/companies')->assertOk();
        $this->as($finance)->deleteJson('/api/v1/companies/1')->assertForbidden();
        $this->as($finance)->patchJson('/api/v1/membership-plans/1', ['monthly_fee_etb' => 1])->assertForbidden();

        // Support: read-only
        $this->as($support)->getJson('/api/v1/companies')->assertOk();
        $this->as($support)->patchJson('/api/v1/companies/1', ['name' => 'X'])->assertForbidden();
        $this->as($support)->getJson('/api/v1/admin/billing/invoices')->assertForbidden();

        $this->assertDatabaseHas('companies', ['id' => 1, 'name' => 'Acme']);
        $this->assertDatabaseHas('membership_plans', ['id' => 1, 'monthly_fee_etb' => 3600]);
    }

    public function test_plan_catalogue_is_readable_by_staff_portals_but_not_members(): void
    {
        $this->as($this->user('company_hr', ['company_id' => 1]))->getJson('/api/v1/membership-plans')->assertOk();
        $this->as($this->user('gym_partner', ['gym_id' => 1]))->getJson('/api/v1/membership-plans')->assertOk();
        $this->as($this->user('member'))->getJson('/api/v1/membership-plans')->assertForbidden();
    }

    public function test_each_portal_is_closed_to_other_kinds_of_account(): void
    {
        $member   = $this->user('member');
        $hr       = $this->user('company_hr', ['company_id' => 1]);
        $partner  = $this->user('gym_partner', ['gym_id' => 1]);
        $employee = $this->user('employee', ['company_id' => 1]);

        // HR portal
        $this->as($member)->postJson('/api/v1/hr/employees/bulk-approve', [])->assertForbidden();
        $this->as($partner)->getJson('/api/v1/hr/my-company')->assertForbidden();
        // Gym portal
        $this->as($member)->getJson('/api/v1/partner-portal/branches')->assertForbidden();
        $this->as($hr)->getJson('/api/v1/partner-portal/expected-visitors')->assertForbidden();
        $this->as($member)->postJson('/api/v1/checkins/scan', [])->assertForbidden();
        // Partner app
        $this->as($member)->getJson('/api/v1/partner/me')->assertForbidden();
        $this->as($hr)->getJson('/api/v1/partner/dashboard')->assertForbidden();
        // Employee web portal
        $this->as($member)->getJson('/api/v1/employee/dashboard')->assertForbidden();
        // Member app: members and employees only
        $this->as($hr)->getJson('/api/v1/mobile/dashboard')->assertForbidden();
        $this->as($partner)->getJson('/api/v1/mobile/qr-token')->assertForbidden();
        $this->assertNotSame(403, $this->as($member)->getJson('/api/v1/mobile/profile')->status());
        $this->assertNotSame(403, $this->as($employee)->getJson('/api/v1/mobile/profile')->status());
    }

    public function test_custom_roles_take_the_scope_they_were_created_with(): void
    {
        Role::create(['name' => 'gym_front_desk_1', 'label' => 'Front desk', 'scope' => 'gym', 'gym_id' => 1]);
        $frontDesk = $this->user('gym_front_desk_1', ['gym_id' => 1]);

        $this->assertSame('gym', $frontDesk->area());
        $this->as($frontDesk)->getJson('/api/v1/companies')->assertForbidden();
        $this->as($frontDesk)->getJson('/api/v1/hr/my-company')->assertForbidden();
    }

    public function test_guests_get_json_401_even_without_an_accept_header(): void
    {
        // Used to throw "Route [login] not defined" (500) for browser-style requests.
        $this->get('/api/v1/companies')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_unknown_roles_get_no_area(): void
    {
        $this->as($this->user('made_up_role'))->getJson('/api/v1/mobile/dashboard')->assertForbidden();
        $this->as($this->user('made_up_role'))->getJson('/api/v1/companies')->assertForbidden();
    }
}
