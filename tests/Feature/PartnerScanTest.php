<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Gym;
use App\Models\GymStaff;
use App\Models\MembershipPlan;
use App\Models\MobileSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * INSA finding CWE-345 on POST /partner/scan: check-ins must be attributed
 * only to the member a valid, unused, short-lived QR token belongs to, at the
 * scanning staff member's own gym.
 */
class PartnerScanTest extends TestCase
{
    use RefreshDatabase;

    private Gym $gym;

    private User $staff;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gym = $this->makeGym('Gym A');
        $this->staff = $this->makeUser('gym_staff');
        $this->member = $this->makeUser('member', ['member_code' => 'FA-T001']);

        GymStaff::forceCreate([
            'user_id' => $this->staff->id,
            'gym_id' => $this->gym->id,
            'role' => 'staff',
            'is_active' => true,
            'must_change_password' => false,
        ]);

        $plan = MembershipPlan::forceCreate([
            'name' => 'Fit Premium',
            'tier' => 'premium',
            'monthly_fee_etb' => 1000,
        ]);

        MobileSubscription::forceCreate([
            'user_id' => $this->member->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);
    }

    public function test_valid_token_records_checkin_for_its_owner(): void
    {
        $token = $this->issueTokenFor($this->member);

        $this->scan($token)
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.member_code', 'FA-T001');

        $this->assertDatabaseHas('checkins', [
            'gym_id' => $this->gym->id,
            'user_id' => $this->member->id,
        ]);
    }

    public function test_token_cannot_be_replayed(): void
    {
        $token = $this->issueTokenFor($this->member);

        $this->scan($token)->assertJsonPath('data.valid', true);
        $this->scan($token)->assertJsonPath('data.valid', false);

        $this->assertSame(1, Checkin::count());
    }

    public function test_expired_token_is_rejected(): void
    {
        $token = $this->issueTokenFor($this->member);
        $this->travel(61)->seconds();

        $this->scan($token)->assertJsonPath('data.valid', false);
        $this->assertSame(0, Checkin::count());
    }

    public function test_issuing_new_token_revokes_previous_unused_token(): void
    {
        $old = $this->issueTokenFor($this->member);
        $new = $this->issueTokenFor($this->member);

        $this->scan($old)->assertJsonPath('data.valid', false);
        $this->scan($new)->assertJsonPath('data.valid', true);
    }

    public function test_tokens_are_stored_hashed(): void
    {
        $token = $this->issueTokenFor($this->member);

        $this->assertDatabaseMissing('qr_tokens', ['token' => $token]);
        $this->assertDatabaseHas('qr_tokens', ['token' => hash('sha256', $token)]);
    }

    public function test_checkin_is_recorded_at_staff_gym_not_client_supplied_gym(): void
    {
        $otherGym = $this->makeGym('Gym B');
        $token = $this->issueTokenFor($this->member);

        $this->scan($token, ['gym_id' => $otherGym->id])->assertForbidden();

        $this->assertSame(0, Checkin::count());
        // The token was not burned by the refused request.
        $this->scan($token)->assertJsonPath('data.valid', true);
        $this->assertDatabaseHas('checkins', ['gym_id' => $this->gym->id]);
    }

    public function test_owner_scan_records_at_selected_own_branch(): void
    {
        $branch = $this->makeGym('Gym A - Bole', ['parent_gym_id' => $this->gym->id]);
        $owner  = $this->makeUser('gym_partner', ['gym_id' => $this->gym->id]);
        $token  = $this->issueTokenFor($this->member);

        Sanctum::actingAs($owner);
        $this->withHeader('X-Gym-Id', (string) $branch->id)
            ->postJson('/api/v1/partner/scan', ['qr_token' => $token])
            ->assertJsonPath('data.valid', true);

        $this->assertDatabaseHas('checkins', ['gym_id' => $branch->id, 'user_id' => $this->member->id]);
    }

    public function test_owner_cannot_record_at_another_owners_gym(): void
    {
        $foreign = $this->makeGym('Someone Else Gym');
        $owner   = $this->makeUser('gym_partner', ['gym_id' => $this->gym->id]);
        $token   = $this->issueTokenFor($this->member);

        Sanctum::actingAs($owner);
        $this->withHeader('X-Gym-Id', (string) $foreign->id)
            ->postJson('/api/v1/partner/scan', ['qr_token' => $token]);

        $this->assertDatabaseMissing('checkins', ['gym_id' => $foreign->id]);
    }

    public function test_non_staff_user_cannot_scan(): void
    {
        $token = $this->issueTokenFor($this->member);
        $outsider = $this->makeUser('member');

        Sanctum::actingAs($outsider);
        $this->postJson('/api/v1/partner/scan', ['qr_token' => $token])->assertForbidden();

        $this->assertSame(0, Checkin::count());
    }

    public function test_client_supplied_identity_is_ignored(): void
    {
        $victim = $this->makeUser('member', ['member_code' => 'FA-T002']);
        $token = $this->issueTokenFor($this->member);

        $this->scan($token, [
            'member_id' => $victim->id,
            'member_code' => 'FA-T002',
            'user_id' => $victim->id,
        ])->assertJsonPath('data.member_code', 'FA-T001');

        $this->assertDatabaseMissing('checkins', ['user_id' => $victim->id]);
    }

    public function test_twelve_hour_rule_still_applies(): void
    {
        $this->scan($this->issueTokenFor($this->member))->assertJsonPath('data.valid', true);

        $this->scan($this->issueTokenFor($this->member))
            ->assertJsonPath('data.valid', false);

        $this->assertSame(1, Checkin::count());
    }

    public function test_scan_endpoint_is_rate_limited(): void
    {
        Sanctum::actingAs($this->staff);

        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/v1/partner/scan', ['qr_token' => 'bogus'])->assertOk();
        }

        $this->postJson('/api/v1/partner/scan', ['qr_token' => 'bogus'])->assertStatus(429);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function issueTokenFor(User $user): string
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/v1/mobile/qr-token')
            ->assertOk()
            ->assertJsonPath('data.expires_in', 60)
            ->json('data.token');
    }

    private function scan(string $token, array $extra = []): TestResponse
    {
        Sanctum::actingAs($this->staff);

        return $this->postJson('/api/v1/partner/scan', ['qr_token' => $token] + $extra);
    }

    private function makeUser(string $role, array $attributes = []): User
    {
        return User::forceCreate($attributes + [
            'name' => ucfirst($role).' '.uniqid(),
            'email' => uniqid($role).'@fitaccess.test',
            'password' => Hash::make('password'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function makeGym(string $name, array $attributes = []): Gym
    {
        return Gym::forceCreate($attributes + [
            'name' => $name,
            'address' => 'Addis Ababa',
            'tier' => 'basic',
            'is_active' => true,
        ]);
    }
}
