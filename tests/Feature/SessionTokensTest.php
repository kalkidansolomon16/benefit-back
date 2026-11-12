<?php

namespace Tests\Feature;

use App\Models\Gym;
use App\Models\GymStaff;
use App\Models\User;
use App\Services\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * INSA finding CWE-613 (/auth/logout): sessions must end on the server —
 * by explicit logout, by idle timeout and absolute lifetime, and by
 * "sign out everywhere", password changes and staff removal.
 */
class SessionTokensTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'Gym-Reception-2026!x';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // Pwned Passwords lookup in StrongPassword
    }

    public function test_login_issues_an_expiring_token_named_after_the_device(): void
    {
        $member = $this->makeUser('member');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $member->email,
            'password' => self::STRONG_PASSWORD,
            'device_name' => 'FitAccess · Pixel 7',
        ])->assertOk();

        $this->assertNotNull($response->json('token_expires_at'));
        // Members: 15-minute access token + rotating refresh token.
        $this->assertSame(15, $response->json('idle_timeout_minutes'));
        $this->assertNotNull($response->json('refresh_token'));
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'FitAccess · Pixel 7']);
    }

    public function test_member_registration_issues_an_expiring_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register/member', [
            'name' => 'New Member',
            'email' => 'new-member@fitaccess.test',
            'phone' => '+251900000001',
            'password' => self::STRONG_PASSWORD,
            'device_name' => 'FitAccess · Android',
        ])->assertCreated();

        $this->assertNotNull($response->json('token_expires_at'));
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'FitAccess · Android']);
    }

    public function test_logout_revokes_the_token_on_the_server(): void
    {
        $token = $this->tokenFor($this->makeUser('member'));

        $this->asBearer($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->asBearer($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->asBearer($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_gym_staff_token_dies_after_twelve_idle_hours(): void
    {
        $token = $this->tokenFor($this->makeUser('gym_staff'));

        $this->travel(11 * 60 + 59)->minutes();
        $this->asBearer($token)->getJson('/api/v1/auth/me')->assertOk();

        $this->travel(12 * 60 + 1)->minutes();
        $this->asBearer($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_gym_staff_token_dies_after_seven_days_even_if_used(): void
    {
        $token = $this->tokenFor($this->makeUser('gym_staff'));

        for ($i = 0; $i < 15; $i++) { // ~7.5 days, used every 12h - 1 min
            $this->travel(12 * 60 - 1)->minutes();
            $response = $this->asBearer($token)->getJson('/api/v1/auth/me');
        }

        $response->assertUnauthorized();
    }

    public function test_member_access_token_dies_after_fifteen_minutes(): void
    {
        $token = $this->tokenFor($this->makeUser('member'));

        $this->travel(14)->minutes();
        $this->asBearer($token)->getJson('/api/v1/auth/me')->assertOk();

        $this->travel(2)->minutes();
        $this->asBearer($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_web_portal_token_dies_after_two_idle_hours(): void
    {
        $token = $this->tokenFor($this->makeUser('company_hr'));

        $this->travel(121)->minutes();
        $this->asBearer($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_tokens_issued_before_expiry_existed_are_still_bounded(): void
    {
        $user = $this->makeUser('gym_staff');
        $legacy = $user->createToken('fitaccess-token'); // expires_at NULL, like existing tokens
        $legacy->accessToken->forceFill([
            'created_at' => now()->subDays(8),
            'last_used_at' => now()->subHour(),
        ])->save();

        $this->asBearer($legacy->plainTextToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_sessions_lists_only_own_live_sessions_and_marks_current(): void
    {
        $user = $this->makeUser('member');
        $current = $this->tokenFor($user, 'Phone A');
        $this->tokenFor($user, 'Tablet B');
        $this->tokenFor($this->makeUser('member'), 'Someone else');

        $response = $this->asBearer($current)->getJson('/api/v1/auth/sessions')->assertOk();

        $this->assertEqualsCanonicalizing(['Phone A', 'Tablet B'], $response->json('data.*.device'));
        $this->assertSame(['Phone A'], collect($response->json('data'))->where('current', true)->pluck('device')->all());
    }

    public function test_user_can_sign_out_another_of_their_own_sessions(): void
    {
        $user = $this->makeUser('member');
        $mine = $this->tokenFor($user, 'Phone A');
        $other = $this->tokenFor($user, 'Tablet B');
        $otherId = PersonalAccessToken::findToken($other)->id;

        $this->asBearer($mine)->deleteJson("/api/v1/auth/sessions/{$otherId}")->assertOk();

        $this->asBearer($other)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->asBearer($mine)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_user_cannot_sign_out_someone_elses_session(): void
    {
        $victim = $this->tokenFor($this->makeUser('member'));
        $victimId = PersonalAccessToken::findToken($victim)->id;
        $attacker = $this->tokenFor($this->makeUser('member'));

        $this->asBearer($attacker)->deleteJson("/api/v1/auth/sessions/{$victimId}")->assertNotFound();
        $this->asBearer($victim)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_sign_out_everywhere_revokes_every_session(): void
    {
        $user = $this->makeUser('gym_staff');
        $a = $this->tokenFor($user, 'Reception phone');
        $b = $this->tokenFor($user, 'Own phone');

        $this->asBearer($a)->postJson('/api/v1/auth/logout-all')->assertOk();

        $this->asBearer($a)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->asBearer($b)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_password_change_ends_other_sessions_but_keeps_this_one(): void
    {
        $user = $this->makeUser('member');
        $current = $this->tokenFor($user, 'Phone A');
        $stolen = $this->tokenFor($user, 'Unknown device');

        $this->asBearer($current)->postJson('/api/v1/auth/change-password', [
            'current_password' => self::STRONG_PASSWORD,
            'new_password' => 'Another-Strong-Pass-99!',
            'new_password_confirmation' => 'Another-Strong-Pass-99!',
        ])->assertOk();

        $this->asBearer($stolen)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->asBearer($current)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_password_reset_ends_every_session(): void
    {
        $user = $this->makeUser('member');
        $token = $this->tokenFor($user);
        $user->forceFill([
            'password_reset_token' => Hash::make('ABCD2345'),
            'password_reset_expires_at' => now()->addHour(),
        ])->save();

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => 'ABCD2345',
            'password' => 'Another-Strong-Pass-99!',
            'password_confirmation' => 'Another-Strong-Pass-99!',
        ])->assertOk();

        $this->asBearer($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_removing_staff_signs_them_out(): void
    {
        $gym = Gym::forceCreate(['name' => 'Gym A', 'address' => 'Addis Ababa', 'tier' => 'basic', 'is_active' => true]);
        $owner = $this->makeUser('gym_staff');
        $staff = $this->makeUser('gym_staff');
        GymStaff::forceCreate(['user_id' => $owner->id, 'gym_id' => $gym->id, 'role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $record = GymStaff::forceCreate(['user_id' => $staff->id, 'gym_id' => $gym->id, 'role' => 'staff', 'is_active' => true, 'must_change_password' => false]);
        $staffToken = $this->tokenFor($staff);

        $this->asBearer($this->tokenFor($owner))->deleteJson("/api/v1/partner/staff/{$record->id}")->assertOk();

        $this->asBearer($staffToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function tokenFor(User $user, ?string $device = null): string
    {
        return app(SessionTokenService::class)->issue($user, $device)->plainTextToken;
    }

    /** A fresh request as the given bearer token (no cached guard user). */
    private function asBearer(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function makeUser(string $role): User
    {
        return User::forceCreate([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => uniqid($role).'@fitaccess.test',
            'password' => Hash::make(self::STRONG_PASSWORD),
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
