<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * INSA member-app finding CWE-613 (excessive token lifetime): members get a
 * 15-minute access token and a rotating, single-use 7-day refresh token; a
 * session ends 14 days after sign-in; reuse of a refresh token kills the
 * session; members can see and revoke their sessions.
 */
class RefreshTokensTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Gym-Reception-2026!x';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // Pwned Passwords lookup
    }

    public function test_member_login_returns_short_access_and_refresh_tokens(): void
    {
        $login = $this->login($this->member());

        $this->assertNotNull($login->json('refresh_token'));
        $this->assertEqualsWithDelta(now()->addMinutes(15)->timestamp, strtotime($login->json('token_expires_at')), 5);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, strtotime($login->json('refresh_expires_at')), 5);
    }

    public function test_staff_and_portal_logins_get_no_refresh_token(): void
    {
        foreach (['gym_staff', 'company_hr'] as $role) {
            $user = $this->member($role);
            $this->asFreshRequest();
            $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD]);
            // company_hr requires MFA first; either way no refresh token is issued.
            $this->assertNull($response->json('refresh_token'), $role);
        }
    }

    public function test_refresh_rotates_both_tokens_and_keeps_member_signed_in(): void
    {
        $login = $this->login($this->member());

        $this->travel(20)->minutes(); // access token expired
        $this->bearer($login->json('token'))->getJson('/api/v1/auth/me')->assertUnauthorized();

        $refreshed = $this->refresh($login->json('refresh_token'))->assertOk();
        $this->assertNotSame($login->json('refresh_token'), $refreshed->json('refresh_token'));
        $this->bearer($refreshed->json('token'))->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_refresh_token_cannot_call_the_api(): void
    {
        $login = $this->login($this->member());

        $this->bearer($login->json('refresh_token'))->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->bearer($login->json('refresh_token'))->getJson('/api/v1/mobile/profile')->assertUnauthorized();
    }

    public function test_reusing_a_refresh_token_revokes_the_whole_session(): void
    {
        $login = $this->login($this->member());
        $stolen = $login->json('refresh_token');

        $legit = $this->refresh($stolen)->assertOk(); // the real app refreshes first
        $this->refresh($stolen)->assertUnauthorized(); // the attacker replays it

        // Everything in that session is now dead, including the legit pair.
        $this->refresh($legit->json('refresh_token'))->assertUnauthorized();
        $this->bearer($legit->json('token'))->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_refresh_token_expires_after_seven_idle_days(): void
    {
        $login = $this->login($this->member());

        $this->travel(7)->days();
        $this->travel(1)->minutes();
        $this->refresh($login->json('refresh_token'))->assertUnauthorized();
    }

    public function test_session_ends_fourteen_days_after_sign_in_even_if_refreshed(): void
    {
        $refresh = $this->login($this->member())->json('refresh_token');

        for ($day = 0; $day < 13; $day++) { // refreshed every day
            $this->travel(1)->days();
            $refresh = $this->refresh($refresh)->assertOk()->json('refresh_token');
        }

        $this->travel(1)->days();
        $this->travel(1)->minutes();
        $this->refresh($refresh)->assertUnauthorized();
    }

    public function test_sign_out_with_refresh_token_ends_the_session(): void
    {
        $login = $this->login($this->member());

        $this->postJson('/api/v1/auth/revoke', ['refresh_token' => $login->json('refresh_token')])->assertOk();

        $this->refresh($login->json('refresh_token'))->assertUnauthorized();
        $this->bearer($login->json('token'))->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_logout_with_access_token_also_kills_refresh_token(): void
    {
        $login = $this->login($this->member());

        $this->bearer($login->json('token'))->postJson('/api/v1/auth/logout')->assertOk();

        $this->refresh($login->json('refresh_token'))->assertUnauthorized();
    }

    public function test_revoke_endpoint_gives_no_hint_about_unknown_tokens(): void
    {
        $this->postJson('/api/v1/auth/revoke', ['refresh_token' => '999|not-a-real-token'])->assertOk();
    }

    public function test_member_sees_one_row_per_device_and_can_revoke_another(): void
    {
        $user = $this->member();
        $phone = $this->login($user, 'FitAccess · Phone');
        $this->asFreshRequest();
        $tablet = $this->login($user, 'FitAccess · Tablet');

        $sessions = $this->bearer($phone->json('token'))->getJson('/api/v1/mobile/sessions')->assertOk();
        $this->assertEqualsCanonicalizing(['FitAccess · Phone', 'FitAccess · Tablet'], $sessions->json('data.*.device'));
        $this->assertSame(['FitAccess · Phone'], collect($sessions->json('data'))->where('current', true)->pluck('device')->all());

        $tabletRow = collect($sessions->json('data'))->firstWhere('device', 'FitAccess · Tablet');
        $this->bearer($phone->json('token'))->deleteJson("/api/v1/auth/sessions/{$tabletRow['id']}")->assertOk();

        $this->refresh($tablet->json('refresh_token'))->assertUnauthorized();
        $this->bearer($tablet->json('token'))->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->bearer($phone->json('token'))->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_password_change_keeps_this_session_and_ends_the_others(): void
    {
        $user = $this->member();
        $here = $this->login($user, 'Here');
        $this->asFreshRequest();
        $other = $this->login($user, 'Other');

        $this->bearer($here->json('token'))->postJson('/api/v1/auth/change-password', [
            'current_password' => self::PASSWORD,
            'new_password' => 'Another-Strong-Pass-99!',
            'new_password_confirmation' => 'Another-Strong-Pass-99!',
        ])->assertOk();

        $this->refresh($here->json('refresh_token'))->assertOk();
        $this->refresh($other->json('refresh_token'))->assertUnauthorized();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function login(User $user, string $device = 'FitAccess · Android'): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'device_name' => $device,
        ])->assertOk();
    }

    private function refresh(string $refreshToken): TestResponse
    {
        $this->asFreshRequest();

        return $this->withHeaders(['Authorization' => ''])
            ->postJson('/api/v1/auth/refresh', ['refresh_token' => $refreshToken]);
    }

    private function bearer(string $token): static
    {
        $this->asFreshRequest();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    /**
     * Login also signs in Laravel's session guard, which Sanctum checks first.
     * Real API requests carry no session (no statefulApi), so clear it to make
     * each test request behave like a separate production request.
     */
    private function asFreshRequest(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    private function member(string $role = 'member'): User
    {
        return User::forceCreate([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => uniqid($role).'@fitaccess.test',
            'password' => Hash::make(self::PASSWORD),
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
