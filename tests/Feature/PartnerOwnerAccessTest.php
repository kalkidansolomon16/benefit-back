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
            'facility' => ['/api/v1/partner/facility'],
            'staff list' => ['/api/v1/partner/staff'],
        ];
    }

    public function test_me_reports_the_role_and_never_money(): void
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
            ->assertJsonMissingPath('data.gym.per_visit_rate')
            ->assertJsonPath('data.gym.tier', 'premium');
    }

    public function test_reports_and_dashboard_carry_no_money(): void
    {
        Sanctum::actingAs($this->staff);
        $this->getJson('/api/v1/partner/reports')
            ->assertOk()
            ->assertJsonPath('data.estimated_payout', null)
            ->assertJsonMissingPath('data.gym.per_visit_rate')
            ->assertJsonMissingPath('data.gym.tier')
            ->assertJsonStructure(['data' => ['summary' => ['total_checkins', 'unique_members', 'active_days']]]);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/partner/reports')->assertOk()
            ->assertJsonMissingPath('data.estimated_payout')
            ->assertJsonMissingPath('data.gym.per_visit_rate');
        $this->getJson('/api/v1/partner/dashboard')->assertOk()
            ->assertJsonMissingPath('data.stats.estimated_payout')
            ->assertJsonMissingPath('data.gym.per_visit_rate')
            ->assertJsonPath('data.gym.name', 'Gym A');
        // The finances endpoint is gone.
        $this->getJson('/api/v1/partner/financials')->assertNotFound();
    }

    public function test_facility_profile_and_member_numbers(): void
    {
        $gym = Gym::first();
        $gym->forceFill([
            'contact_phone' => '+251911000111', 'contact_email' => 'hello@gyma.test', 'contact_person' => 'Selam',
            'sub_city' => 'Bole', 'city' => 'Addis Ababa', 'opening_hours' => ['mon_fri' => '06:00 – 22:00'],
            'facilities' => ['showers'], 'amenities' => json_encode(['parking', 'showers']),
        ])->save();

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/partner/facility')->assertOk()
            ->assertJsonPath('data.phone', '+251911000111')
            ->assertJsonPath('data.email', 'hello@gyma.test')
            ->assertJsonPath('data.opening_hours.mon_fri', '06:00 – 22:00')
            ->assertJsonPath('data.amenities', ['parking', 'showers'])
            ->assertJsonPath('data.staff_count', 2)
            ->assertJsonMissingPath('data.per_visit_rate');

        $this->patchJson('/api/v1/partner/facility', ['phone' => '+251911000222', 'contact_person' => 'Abebe'])->assertOk()
            ->assertJsonPath('data.phone', '+251911000222')->assertJsonPath('data.contact_person', 'Abebe');

        // Check-in lists show the 12-digit number, never the FA- account code.
        $member = User::forceCreate(['name' => 'Hana', 'email' => 'hana@x.test', 'password' => 'x', 'role' => 'member', 'is_active' => true, 'member_code' => 'FA-00388', 'member_number' => '482109375512']);
        \App\Models\Checkin::forceCreate(['user_id' => $member->id, 'gym_id' => $gym->id, 'checked_in_at' => now()]);
        $this->getJson('/api/v1/partner/dashboard')->assertJsonPath('data.recent_checkins.0.member_code', '482109375512');
        $this->getJson('/api/v1/partner/visits/today')->assertJsonPath('data.0.member_code', '482109375512');
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
