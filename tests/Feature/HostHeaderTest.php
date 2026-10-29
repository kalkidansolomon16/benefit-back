<?php

namespace Tests\Feature;

use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * INSA finding CWE-20 (host header injection, /api/v1/auth/forgot-password):
 * forged Host headers are rejected, and links the API builds never use the
 * request's Host.
 */
class HostHeaderTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://fitaccess-api.kalkidan.net';

    protected function setUp(): void
    {
        parent::setUp();

        // Production behaviour (it is off by default in tests).
        config([
            'fitaccess.enforce_trusted_hosts' => true,
            'fitaccess.api_url' => self::API,
            'fitaccess.trusted_hosts' => ['fitaccess-api.kalkidan.net', 'www.fitaccess-api.kalkidan.net'],
        ]);
        (new AppServiceProvider($this->app))->boot();
    }

    public function test_forged_host_is_rejected_before_any_email_is_sent(): void
    {
        Mail::fake();
        $user = $this->makeUser();

        $this->withHeader('Host', 'evil.com')
            ->postJson('http://evil.com/api/v1/auth/forgot-password', ['email' => $user->email])
            ->assertStatus(400);

        Mail::assertNothingSent();
    }

    /** @dataProvider forgedHosts */
    public function test_any_untrusted_host_gets_400(string $host): void
    {
        $this->getJson("http://{$host}/api/v1/public/plans")->assertStatus(400);
    }

    public static function forgedHosts(): array
    {
        return [
            'attacker domain' => ['evil.com'],
            'look-alike' => ['fitaccess-api.kalkidan.net.evil.com'],
            'other site on the same server' => ['fitaccess.kalkidan.net'],
            'raw IP' => ['127.0.0.1'],
        ];
    }

    public function test_real_api_hosts_still_work(): void
    {
        Mail::fake();
        $user = $this->makeUser();

        foreach (['fitaccess-api.kalkidan.net', 'www.fitaccess-api.kalkidan.net'] as $host) {
            $this->postJson("https://{$host}/api/v1/auth/forgot-password", ['email' => $user->email])
                ->assertOk();
        }

        Mail::assertSent(PasswordResetCodeMail::class);
    }

    public function test_generated_links_use_the_configured_api_address(): void
    {
        // Even if a request with a forged Host got this far, url() ignores it.
        $this->app->instance('request', \Illuminate\Http\Request::create('http://evil.com/x'));
        URL::setRequest($this->app['request']);

        $this->assertSame(self::API.'/api/v1/files/a.jpg', url('/api/v1/files/a.jpg'));
    }

    public function test_reset_link_uses_the_api_address_not_a_lan_ip(): void
    {
        $user = $this->makeUser();
        $mail = (new ResetPassword('tok en'))->toMail($user);

        $this->assertStringStartsWith(self::API.'/api/v1/auth/reset-password-redirect?token=tok+en', $mail->actionUrl);
        $this->assertStringNotContainsString('192.168.', $mail->actionUrl);
    }

    public function test_check_is_off_outside_production_by_default(): void
    {
        config(['fitaccess.enforce_trusted_hosts' => false]);

        $this->getJson('http://evil.com/api/v1/public/plans')->assertStatus(200);
    }

    private function makeUser(): User
    {
        return User::forceCreate([
            'name' => 'Reset Tester',
            'email' => uniqid('reset').'@fitaccess.test',
            'password' => Hash::make('Gym-Reception-2026!x'),
            'role' => 'member',
            'is_active' => true,
        ]);
    }
}
