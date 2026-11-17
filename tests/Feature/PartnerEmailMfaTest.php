<?php

namespace Tests\Feature;

use App\Mail\LoginCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Second sign-in step: a 6-digit code emailed to the account (website format:
 * mfa_setup_required + mfa_sent_to, confirmed at auth/mfa/setup/confirm).
 * auth/mfa/verify stays as the same check for mobile builds that call it.
 */
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
        $this->owner = $this->account('owner@kuriftu.test', 'gym_partner');
    }

    public function test_right_password_sends_a_code_and_the_code_signs_in(): void
    {
        $r = $this->login()->assertOk()
            ->assertJsonPath('mfa_setup_required', true)
            ->assertJsonPath('mfa_method', 'email')
            ->assertJsonPath('qr_code', null)
            ->assertJsonMissingPath('token');
        $this->assertStringEndsWith('@kuriftu.test', $r->json('mfa_sent_to'));

        $code = $this->lastCode();
        $this->postJson('/api/v1/auth/mfa/setup/confirm', ['mfa_token' => $r->json('mfa_token'), 'code' => $code, 'device_name' => 'FitAccess Partner · Android'])
            ->assertOk()->assertJsonPath('user.role', 'gym_partner')->assertJsonStructure(['token']);

        // The sign-in attempt is used up.
        $this->postJson('/api/v1/auth/mfa/setup/confirm', ['mfa_token' => $r->json('mfa_token'), 'code' => $code])->assertUnprocessable();
    }

    public function test_the_mobile_path_checks_the_same_code(): void
    {
        $token = $this->login()->json('mfa_token');
        $code = $this->lastCode();
        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $token, 'code' => $code === '000000' ? '111111' : '000000'])
            ->assertUnprocessable()->assertJsonPath('code', 'wrong_code');
        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $token, 'code' => $code])
            ->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $token, 'code' => $code])
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

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/mfa/setup/confirm', ['mfa_token' => $token, 'code' => $wrong])->assertUnprocessable();
        }
        $status = $this->postJson('/api/v1/auth/mfa/setup/confirm', ['mfa_token' => $token, 'code' => $code])->status();
        $this->assertContains($status, [422, 429], 'even the right code is refused now');
    }

    public function test_a_code_only_works_for_its_own_sign_in(): void
    {
        $first = $this->login()->json('mfa_token');
        $firstCode = $this->lastCode();
        Mail::fake();
        $second = $this->login()->json('mfa_token');
        $secondCode = $this->lastCode();
        if ($firstCode === $secondCode) {
            $this->markTestSkipped('both random codes happened to match');
        }
        $this->postJson('/api/v1/auth/mfa/setup/confirm', ['mfa_token' => $second, 'code' => $firstCode])->assertUnprocessable();
        $this->postJson('/api/v1/auth/mfa/setup/confirm', ['mfa_token' => $first, 'code' => $firstCode])->assertOk();
    }

    public function test_resend_waits_a_minute_then_sends_a_fresh_code(): void
    {
        $token = $this->login()->json('mfa_token');
        $this->postJson('/api/v1/auth/mfa/resend', ['mfa_token' => $token])->assertStatus(429);

        // A minute later (the resend wait uses the stored send time).
        $state = Cache::get("mfa_code:{$token}");
        Cache::put("mfa_code:{$token}", ['sent_at' => time() - 61] + $state, now()->addMinutes(10));
        Mail::fake();
        $this->postJson('/api/v1/auth/mfa/resend', ['mfa_token' => $token])->assertOk();
        $this->postJson('/api/v1/auth/mfa/setup/confirm', ['mfa_token' => $token, 'code' => $this->lastCode()])->assertOk();
    }

    public function test_employees_get_an_email_code_too(): void
    {
        $employee = $this->account('abebe@ethiotelecom.test', 'employee');
        $this->postJson('/api/v1/auth/login', ['email' => $employee->email, 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('mfa_method', 'email')->assertJsonMissingPath('token');
        Mail::assertSent(LoginCodeMail::class, fn ($m) => $m->hasTo('abebe@ethiotelecom.test'));
    }

    private function account(string $email, string $role): User
    {
        return User::forceCreate(['name' => 'Test '.$role, 'email' => $email, 'password' => Hash::make(self::PASSWORD), 'role' => $role, 'is_active' => true]);
    }

    private function login()
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $this->owner->email, 'password' => self::PASSWORD, 'device_name' => 'FitAccess Partner · Android']);
    }

    private function lastCode(): string
    {
        $code = null;
        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });

        return $code;
    }
}
