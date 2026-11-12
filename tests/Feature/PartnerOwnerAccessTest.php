<?php

namespace Tests\Feature;

use App\Models\Gym;
use App\Models\GymStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * INSA finding CWE-602: owner-only data must be enforced by the server on
 * every request (GETs included), whatever the app shows or has cached.
 */
class PartnerOwnerAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $gym = Gym::forceCreate([
            'name' => 'Gym A', 'address' => 'Addis Ababa', 'tier' => 'premium',
            'per_visit_rate' => 150, 'is_active' => true,
        ]);
        $this->owner = $this->member($gym, 'gym_partner', 'admin');
        $this->staff = $this->member($gym, 'gym_staff', 'staff');
    }

    /** @dataProvider ownerOnlyEndpoints */
    public function test_staff_are_refused_owner_only_endpoints(string $uri): void
    {
        Sanctum::actingAs($this->staff);
        $this->getJson($uri)->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->getJson($uri)->assertOk();
    }

    public static function ownerOnlyEndpoints(): array
    {
        return [
            'dashboard' => ['/api/v1/partner/dashboard'],
            'financials' => ['/api/v1/partner/financials'],
            'staff list' => ['/api/v1/partner/staff'],
        ];
    }

    public function test_me_reports_the_role_and_hides_commercial_terms_from_staff(): void
    {
        Sanctum::actingAs($this->staff);
        $staffMe = $this->getJson('/api/v1/partner/me')->assertOk();
        $staffMe->assertJsonPath('data.is_owner', false)
            ->assertJsonPath('data.gym.name', 'Gym A')
            ->assertJsonMissingPath('data.gym.per_visit_rate')
            ->assertJsonMissingPath('data.gym.tier');

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/partner/me')
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.gym.per_visit_rate', 150)
            ->assertJsonPath('data.gym.tier', 'premium');
    }

    public function test_reports_hide_payout_and_rates_from_staff(): void
    {
        Sanctum::actingAs($this->staff);
        $this->getJson('/api/v1/partner/reports')
            ->assertOk()
            ->assertJsonPath('data.estimated_payout', null)
            ->assertJsonMissingPath('data.gym.per_visit_rate')
            ->assertJsonMissingPath('data.gym.tier')
            ->assertJsonStructure(['data' => ['summary' => ['total_checkins', 'unique_members', 'active_days']]]);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/partner/reports')
            ->assertJsonPath('data.estimated_payout.rate_per_visit', 150)
            ->assertJsonPath('data.gym.per_visit_rate', 150);
    }

    public function test_staff_can_still_use_their_own_screens(): void
    {
        Sanctum::actingAs($this->staff);

        $this->getJson('/api/v1/partner/visits/today')->assertOk();
        $this->getJson('/api/v1/partner/visits/monthly')->assertOk();
        $this->getJson('/api/v1/partner/reports')->assertOk();
    }

    private function member(Gym $gym, string $role, string $staffRole): User
    {
        $user = User::forceCreate([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => uniqid($role).'@fitaccess.test',
            'password' => Hash::make('password'),
            'role' => $role,
            'is_active' => true,
        ]);
        GymStaff::forceCreate([
            'user_id' => $user->id, 'gym_id' => $gym->id, 'role' => $staffRole,
            'is_active' => true, 'must_change_password' => false,
        ]);

        return $user;
    }
}
