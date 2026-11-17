<?php

namespace Tests\Feature;

use App\Mail\LoginCodeMail;
use App\Models\Gym;
use App\Models\GymStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** "Set your own password" after sign-in follows users.must_reset_password. */
class PartnerPasswordChangeFlagTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Gym-Owner-2026!x';

    private Gym $gym;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake();
        Cache::flush();
        $this->gym = Gym::forceCreate(['name' => 'Kuriftu Gym', 'address' => 'Bole', 'tier' => 'basic', 'is_active' => true]);
    }

    public function test_owner_who_changed_the_password_on_the_website_is_not_asked_again(): void
    {
        // Approval left the gym_staff flag on; the website only cleared the users one.
        $owner = $this->partner('owner@kuriftu.test', mustReset: false, staffFlag: true);
        $this->signIn($owner)->assertJsonPath('must_change_password', false)->assertJsonPath('user.must_change_password', false);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/partner/me')->assertJsonPath('data.must_change_password', false);
    }

    public function test_owner_with_a_temporary_password_must_change_it(): void
    {
        $owner = $this->partner('owner@kuriftu.test', mustReset: true, staffFlag: false);
        $this->signIn($owner)->assertJsonPath('must_change_password', true);
    }

    public function test_changing_it_on_the_website_clears_both_flags(): void
    {
        $owner = $this->partner('owner@kuriftu.test', mustReset: true, staffFlag: true);
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/auth/first-login-reset', ['password' => 'New-Owner-Pass-2026!', 'password_confirmation' => 'New-Owner-Pass-2026!'])->assertOk();

        $this->assertFalse((bool) $owner->fresh()->must_reset_password);
        $this->assertFalse((bool) GymStaff::where('user_id', $owner->id)->value('must_change_password'));
    }

    public function test_invited_staff_still_change_their_temporary_password(): void
    {
        $staff = User::forceCreate(['name' => 'Desk', 'email' => 'desk@kuriftu.test', 'password' => Hash::make(self::PASSWORD), 'role' => 'gym_staff', 'is_active' => true]);
        GymStaff::forceCreate(['user_id' => $staff->id, 'gym_id' => $this->gym->id, 'role' => 'staff', 'is_active' => true, 'must_change_password' => true]);

        $this->assertTrue($staff->mustChangePasswordNow(GymStaff::where('user_id', $staff->id)->first()));
    }

    private function partner(string $email, bool $mustReset, bool $staffFlag): User
    {
        $u = User::forceCreate(['name' => 'Selam Gym Owner', 'email' => $email, 'password' => Hash::make(self::PASSWORD), 'role' => 'gym_partner', 'is_active' => true, 'must_reset_password' => $mustReset, 'gym_id' => $this->gym->id]);
        GymStaff::forceCreate(['user_id' => $u->id, 'gym_id' => $this->gym->id, 'role' => 'admin', 'is_active' => true, 'must_change_password' => $staffFlag]);

        return $u;
    }

    private function signIn(User $u)
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => self::PASSWORD])->assertOk()->json('mfa_token');
        $code = null;
        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });

        return $this->postJson('/api/v1/auth/mfa/setup/confirm', ['mfa_token' => $token, 'code' => $code])->assertOk();
    }
}
