<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Gym;
use App\Models\GymStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * INSA finding CWE-345 on GET /partner/reports: figures must come only from
 * the staff member's own gym, and the month parameter must be validated.
 */
class PartnerReportsTest extends TestCase
{
    use RefreshDatabase;

    private Gym $gym;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gym = $this->makeGym('Gym A');
        $this->staff = $this->makeUser('gym_staff');

        GymStaff::forceCreate([
            'user_id' => $this->staff->id,
            'gym_id' => $this->gym->id,
            'role' => 'staff',
            'is_active' => true,
            'must_change_password' => false,
        ]);

        Sanctum::actingAs($this->staff);
    }

    public function test_figures_match_the_recorded_checkins(): void
    {
        $alice = $this->makeUser('member');
        $bob = $this->makeUser('member');
        $this->checkin($alice, '2026-09-03 08:00:00');
        $this->checkin($alice, '2026-09-04 08:00:00');
        $this->checkin($bob, '2026-09-04 18:00:00');
        $this->checkin($bob, '2026-08-30 18:00:00'); // previous month

        $this->getJson('/api/v1/partner/reports?month=2026-09')
            ->assertOk()
            ->assertJsonPath('data.period', 'September 2026')
            ->assertJsonPath('data.summary.total_checkins', 3)
            ->assertJsonPath('data.summary.unique_members', 2)
            ->assertJsonPath('data.summary.active_days', 2)
            ->assertJsonPath('data.daily_activity.3.count', 2);
    }

    public function test_other_gyms_checkins_are_never_counted(): void
    {
        $other = $this->makeGym('Gym B');
        $this->checkin($this->makeUser('member'), '2026-09-10 08:00:00', $other);

        $this->getJson('/api/v1/partner/reports?month=2026-09')
            ->assertJsonPath('data.summary.total_checkins', 0);
    }

    /** @dataProvider invalidMonths */
    public function test_invalid_month_is_rejected(string $month): void
    {
        $this->getJson('/api/v1/partner/reports?month='.urlencode($month))
            ->assertStatus(422);

        $this->getJson('/api/v1/partner/visits/monthly?month='.urlencode($month))
            ->assertStatus(422);
    }

    public static function invalidMonths(): array
    {
        return [
            'not a date' => ['abc'],
            'month 13' => ['2026-13'],
            'full date' => ['2026-09-01'],
            'sql-ish' => ["2026-09' OR 1=1"],
        ];
    }

    public function test_month_does_not_roll_over_at_month_end(): void
    {
        $this->travelTo('2026-03-31 12:00:00');

        $this->getJson('/api/v1/partner/reports?month=2026-02')
            ->assertOk()
            ->assertJsonPath('data.period', 'February 2026')
            ->assertJsonCount(28, 'data.daily_activity');
    }

    public function test_month_defaults_to_current(): void
    {
        $this->travelTo('2026-10-19 12:00:00');

        $this->getJson('/api/v1/partner/reports')
            ->assertOk()
            ->assertJsonPath('data.period', 'October 2026');
    }

    private function checkin(User $member, string $at, ?Gym $gym = null): void
    {
        Checkin::forceCreate([
            'gym_id' => ($gym ?? $this->gym)->id,
            'user_id' => $member->id,
            'checked_in_at' => $at,
            'method' => 'qr_code',
        ]);
    }

    private function makeUser(string $role): User
    {
        return User::forceCreate([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => uniqid($role).'@fitaccess.test',
            'password' => Hash::make('password'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function makeGym(string $name): Gym
    {
        return Gym::forceCreate([
            'name' => $name,
            'address' => 'Addis Ababa',
            'tier' => 'basic',
            'is_active' => true,
        ]);
    }
}
