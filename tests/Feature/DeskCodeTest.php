<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Gym;
use App\Models\GymStaff;
use App\Models\MembershipPlan;
use App\Models\MobileSubscription;
use App\Models\QrToken;
use App\Models\User;
use App\Services\QrTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * v2: 30-second check-in tokens with a 6-digit front-desk code that
 * reception can type in when the scan fails.
 */
class DeskCodeTest extends TestCase
{
    use RefreshDatabase;

    private Gym $gym;
    private User $staff;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->gym = Gym::forceCreate(['name' => 'Gym A', 'address' => 'Addis Ababa', 'tier' => 'basic', 'is_active' => true]);
        $this->staff = $this->user('gym_staff');
        $this->member = $this->user('member', ['member_code' => 'FA-T001']);

        GymStaff::forceCreate(['user_id' => $this->staff->id, 'gym_id' => $this->gym->id, 'role' => 'staff', 'is_active' => true, 'must_change_password' => false]);
        $plan = MembershipPlan::forceCreate(['name' => 'Fit Premium', 'tier' => 'premium', 'monthly_fee_etb' => 1000]);
        MobileSubscription::forceCreate([
            'user_id' => $this->member->id, 'plan_id' => $plan->id, 'status' => 'active',
            'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addMonth()->toDateString(),
        ]);
    }

    public function test_token_lives_30_seconds_and_comes_with_a_desk_code(): void
    {
        $data = $this->issue();

        $this->assertSame(30, $data['expires_in']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $data['desk_code']);
    }

    public function test_desk_code_is_never_stored_in_plain_text(): void
    {
        $data = $this->issue();
        $row = QrToken::first();

        $this->assertNotSame($data['desk_code'], $row->desk_code_hash);
        $this->assertStringNotContainsString($data['desk_code'], (string) $row->desk_code_hash);
        $this->assertSame(64, strlen($row->desk_code_hash));
    }

    public function test_desk_code_checks_the_member_in(): void
    {
        $code = $this->issue()['desk_code'];

        $this->scan(['desk_code' => $code])->assertOk()->assertJsonPath('data.valid', true);

        $this->assertDatabaseHas('checkins', ['user_id' => $this->member->id, 'gym_id' => $this->gym->id, 'method' => 'desk_code']);
    }

    public function test_desk_code_and_qr_are_one_single_use_token(): void
    {
        $data = $this->issue();

        $this->scan(['desk_code' => $data['desk_code']])->assertJsonPath('data.valid', true);
        $this->scan(['qr_token' => $data['token']])->assertJsonPath('data.valid', false);
        $this->scan(['desk_code' => $data['desk_code']])->assertJsonPath('data.valid', false);

        $this->assertSame(1, Checkin::count());
    }

    public function test_a_new_code_replaces_the_old_one(): void
    {
        $old = $this->issue()['desk_code'];
        $this->issue();

        $this->scan(['desk_code' => $old])->assertJsonPath('data.valid', false);
    }

    public function test_desk_code_expires_with_the_token(): void
    {
        $code = $this->issue()['desk_code'];
        $this->travel(QrTokenService::TTL_SECONDS + 1)->seconds();

        $this->scan(['desk_code' => $code])->assertJsonPath('data.valid', false);
    }

    public function test_wrong_codes_are_locked_out_per_staff_account(): void
    {
        $real = $this->issue()['desk_code'];
        $wrong = $real === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 10; $i++) {
            $this->scan(['desk_code' => $wrong])->assertOk()->assertJsonPath('data.valid', false);
        }

        // Even the right code is refused now; the QR still works.
        $this->scan(['desk_code' => $real])->assertStatus(429);
        $this->assertSame(0, Checkin::count());
    }

    public function test_desk_code_must_be_six_digits(): void
    {
        $this->scan(['desk_code' => '12ab56'])->assertUnprocessable();
        $this->scan([])->assertUnprocessable();
    }

    public function test_non_staff_cannot_use_desk_codes(): void
    {
        $code = $this->issue()['desk_code'];
        Sanctum::actingAs($this->member);

        $this->postJson('/api/v1/partner/scan', ['desk_code' => $code])->assertForbidden();
        $this->assertSame(0, Checkin::count());
    }

    private function issue(): array
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->member);

        return $this->getJson('/api/v1/mobile/qr-token')->assertOk()->json('data');
    }

    private function scan(array $body)
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->staff);

        return $this->postJson('/api/v1/partner/scan', $body);
    }

    private function user(string $role, array $attributes = []): User
    {
        return User::forceCreate($attributes + [
            'name' => ucfirst($role).' '.uniqid(),
            'email' => uniqid($role).'@fitaccess.test',
            'password' => Hash::make('password'),
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
