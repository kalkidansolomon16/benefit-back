<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Gym;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Support\Tier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Plans are stored as names ("Fit Premium") and the top tier has two spellings
 * (premium / platinum). A Premium employee must see Fit Premium in the portal
 * and reach every gym; higher packages include the lower ones' gyms.
 */
class EmployeePackageTest extends TestCase
{
    use RefreshDatabase;

    private function employee(string $level): Employee
    {
        $company = Company::forceCreate([
            'name' => 'Pkg Bank ' . $level, 'industry' => 'banking', 'contact_email' => "hr-{$level}@pkg.test",
            'contact_person' => 'HR', 'is_active' => true, 'company_status' => 'active',
        ]);
        $u = User::forceCreate([
            'name' => "Emp {$level}", 'email' => "{$level}@pkg.test", 'password' => Hash::make('x'),
            'role' => 'employee', 'is_active' => true,
        ]);
        return Employee::create([
            'user_id' => $u->id, 'company_id' => $company->id, 'fan_number' => "FAN-{$level}",
            'level' => $level, 'registration_status' => 'approved', 'is_enrolled' => true,
            'payment_status' => 'paid', 'paid_until' => now()->addMonth()->toDateString(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Exactly how production stores them: the tier column holds the plan name
        MembershipPlan::create(['name' => 'Fit Basic',      'tier' => 'Fit Basic',      'monthly_fee_etb' => 1000, 'is_active' => true]);
        MembershipPlan::create(['name' => 'Fit Basic Plus', 'tier' => 'Fit Basic Plus', 'monthly_fee_etb' => 2000, 'is_active' => true]);
        MembershipPlan::create(['name' => 'Fit Premium',    'tier' => 'Fit Premium',    'monthly_fee_etb' => 3000, 'is_active' => true]);
        Gym::forceCreate(['name' => 'Basic Gym',   'address' => 'A', 'tier' => 'basic',      'is_active' => true]);
        Gym::forceCreate(['name' => 'Plus Gym',    'address' => 'B', 'tier' => 'basic_plus', 'is_active' => true]);
        Gym::forceCreate(['name' => 'Premium Spa', 'address' => 'C', 'tier' => 'premium',    'is_active' => true]);
    }

    public function test_tier_rules(): void
    {
        $this->assertSame('premium', Tier::canonical('Fit Premium'));
        $this->assertSame('premium', Tier::canonical('platinum'));
        $this->assertSame('basic_plus', Tier::canonical('Fit Basic Plus'));
        $this->assertSame('basic', Tier::canonical('Fit Basic'));
        $this->assertTrue(Tier::allows('Fit Premium', 'basic'));
        $this->assertFalse(Tier::allows('Fit Basic', 'basic_plus'));
    }

    public function test_premium_employee_sees_fit_premium_and_all_gyms(): void
    {
        Sanctum::actingAs($this->employee('chief')->user);
        $res = $this->getJson('/api/v1/employee/dashboard')->assertOk();

        $res->assertJsonPath('pass.plan_name', 'Fit Premium');
        $res->assertJsonPath('pass.plan_key', 'premium');
        $this->assertSame(['basic', 'basic_plus', 'premium'], $res->json('pass.accessible_tiers'));
        $this->assertEqualsCanonicalizing(['Basic Gym', 'Plus Gym', 'Premium Spa'], collect($res->json('accessible_gyms'))->pluck('name')->all());
    }

    public function test_basic_plus_and_basic_employees_see_their_own_package(): void
    {
        Sanctum::actingAs($this->employee('director')->user);
        $res = $this->getJson('/api/v1/employee/dashboard')->assertOk()->assertJsonPath('pass.plan_name', 'Fit Basic Plus');
        $this->assertEqualsCanonicalizing(['Basic Gym', 'Plus Gym'], collect($res->json('accessible_gyms'))->pluck('name')->all());

        Sanctum::actingAs($this->employee('staff')->user);
        $res = $this->getJson('/api/v1/employee/dashboard')->assertOk()->assertJsonPath('pass.plan_name', 'Fit Basic');
        $this->assertSame(['Basic Gym'], collect($res->json('accessible_gyms'))->pluck('name')->all());
    }
}
