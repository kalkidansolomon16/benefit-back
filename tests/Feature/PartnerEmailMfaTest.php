<?php

namespace Tests\Feature;

use App\Mail\FitAccessNotificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Gym owners sign in with password + a code sent to their email. */
class PartnerEmailMfaTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Gym-Owner-2026!x';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::flush();
        $this->owner = User::forceCreate([
            'name' => 'Selam Gym Owner', 'email' => 'owner@kuriftu.test',
            'password' => Hash::make(self::PASSWORD), 'role' => 'gym_partner', 'is_active' => true,
        ]);
    }

    public function test_right_password_sends_a_code_and_the_code_signs_in(): void
    {
        $r = $this->login()->assertOk()
            ->assertJsonPath('mfa_required', true)
            ->assertJsonPath('mfa_method', 'email')
            ->assertJsonPath('masked_contact', 'o••••••@kuriftu.test')
            ->assertJsonMissingPath('token');

        $code = $this->lastCode();
        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $r->json('mfa_token'), 'code' => $code, 'device_name' => 'FitAccess Partner · Android'])
            ->assertOk()
            ->assertJsonPath('user.role', 'gym_partner')
            ->assertJsonStructure(['token']);

        // The sign-in attempt is used up.
        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $r->json('mfa_token'), 'code' => $code])
            ->assertUnprocessable()->assertJsonPath('code', 'session_expired');
    }

    public function test_wrong_password_sends_nothing(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => $this->owner->email, 'password' => 'not-it'])->assertUnprocessable()->assertJsonMissingPath('mfa_token');
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@kuriftu.test', 'password' => self::PASSWORD])->assertUnprocessable()->assertJsonMissingPath('mfa_token');
        Mail::assertNothingSent();
    }

    public function test_five_wrong_codes_end_the_attempt(): void
    {
        $token = $this->login()->json('mfa_token');
        $code = $this->lastCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $token, 'code' => $wrong])->assertJsonPath('code', 'wrong_code');
        }
        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $token, 'code' => $wrong])->assertJsonPath('code', 'code_expired');
        // Even the right code is refused now (422), or the sign-in rate limit
        // has already stepped in (429).
        $status = $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $token, 'code' => $code])->status();
        $this->assertContains($status, [422, 429]);
    }

    public function test_a_code_only_works_for_its_own_sign_in(): void
    {
        $first = $this->login()->json('mfa_token');
        $firstCode = $this->lastCode();
        Mail::fake();
        $second = $this->login()->json('mfa_token');

        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $second, 'code' => $firstCode === $this->lastCode() ? '999999' : $firstCode])
            ->assertUnprocessable();
        $this->assertNotSame($first, $second);
    }

    public function test_resend_sends_a_fresh_code(): void
    {
        $token = $this->login()->json('mfa_token');
        Mail::fake();
        $this->postJson('/api/v1/auth/mfa/resend', ['mfa_token' => $token])->assertOk()->assertJsonPath('data.masked_contact', 'o••••••@kuriftu.test');
        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $token, 'code' => $this->lastCode()])->assertOk();

        $this->postJson('/api/v1/auth/mfa/resend', ['mfa_token' => 'made-up'])->assertUnprocessable();
    }

    public function test_the_authenticator_route_is_closed_for_gym_owners(): void
    {
        $this->owner->forceFill(['google2fa_secret' => 'JBSWY3DPEHPK3PXP', 'google2fa_enabled' => true])->save();
        $token = $this->login()->assertJsonPath('mfa_method', 'email')->json('mfa_token');

        $this->postJson('/api/v1/auth/mfa/setup/confirm', ['mfa_token' => $token, 'code' => '123456'])
            ->assertUnprocessable()->assertJsonPath('code', 'use_email_code');
    }

    public function test_admins_keep_the_authenticator(): void
    {
        User::forceCreate(['name' => 'Admin', 'email' => 'admin@fitaccess.test', 'password' => Hash::make(self::PASSWORD), 'role' => 'fitaccess_admin', 'is_active' => true]);
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@fitaccess.test', 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('mfa_setup_required', true)->assertJsonMissingPath('mfa_method');
        Mail::assertNothingSent();
    }

    private function login()
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $this->owner->email, 'password' => self::PASSWORD, 'device_name' => 'FitAccess Partner · Android']);
    }

    private function lastCode(): string
    {
        $code = null;
        Mail::assertSent(FitAccessNotificationMail::class, function ($m) use (&$code) {
            if ($m->hasTo('owner@kuriftu.test') && $m->emailSubject === 'Your FitAccess confirmation code') {
                $code = $m->heading;

                return true;
            }

            return false;
        });

        return $code;
    }
}
