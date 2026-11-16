<?php

namespace Tests\Feature;

use App\Mail\FitAccessNotificationMail;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Member app sign-up: the company-registered email + a password, confirmed by a code. */
class EmployeeSignupTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Abebe-Strong-2026!x';

    private User $user;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake(); // Pwned Passwords lookup in StrongPassword
        Cache::flush();
        $company = Company::forceCreate(['name' => 'Ethio Telecom', 'contact_email' => 'hr@et.test', 'contact_person' => 'HR', 'contact_phone' => '1', 'address' => 'a', 'city' => 'Addis Ababa']);
        // As HR creates it: temporary password, must reset, approved (active).
        $this->user = User::forceCreate(['name' => 'Abebe Kebede', 'email' => 'abebe@ethiotelecom.test', 'password' => Hash::make('temp-Pass-123!x'), 'role' => 'employee', 'is_active' => true, 'must_reset_password' => true, 'company_id' => $company->id]);
        $this->employee = Employee::forceCreate(['user_id' => $this->user->id, 'company_id' => $company->id, 'fan_number' => 'FAN-1', 'is_enrolled' => true, 'registration_status' => 'approved', 'admin_approval_status' => 'approved']);
        $this->employee->refresh();
    }

    public function test_sign_up_with_the_company_email_and_a_code(): void
    {
        $token = $this->signup('Abebe@EthioTelecom.test')
            ->assertOk()->assertJsonPath('mfa_method', 'email')->assertJsonPath('masked_contact', 'a••••••@ethiotelecom.test')
            ->assertJsonMissingPath('token')->json('mfa_token');

        $this->assertTrue(Hash::check('temp-Pass-123!x', $this->user->fresh()->password), 'nothing changes before the code');

        $this->postJson('/api/v1/auth/signup/verify', ['mfa_token' => $token, 'code' => $this->code(), 'device_name' => 'FitAccess · Android'])
            ->assertOk()->assertJsonPath('user.role', 'employee')->assertJsonStructure(['token', 'refresh_token']);

        $u = $this->user->fresh();
        $this->assertTrue(Hash::check(self::PASSWORD, $u->password));
        $this->assertFalse((bool) $u->must_reset_password);

        // From now on it's a normal sign-in.
        $this->signup('abebe@ethiotelecom.test')->assertStatus(409)->assertJsonPath('code', 'already_registered');
    }

    public function test_unknown_emails_and_waiting_accounts_are_told_what_to_do(): void
    {
        $this->signup('someone@gmail.test')->assertNotFound()->assertJsonPath('code', 'not_registered');

        $this->user->forceFill(['is_active' => false])->save();
        $this->signup('abebe@ethiotelecom.test')->assertForbidden()->assertJsonPath('code', 'pending_approval');
        Mail::assertNothingSent();
    }

    public function test_a_wrong_code_changes_nothing(): void
    {
        $token = $this->signup('abebe@ethiotelecom.test')->json('mfa_token');
        $wrong = $this->code() === '000000' ? '111111' : '000000';
        $this->postJson('/api/v1/auth/signup/verify', ['mfa_token' => $token, 'code' => $wrong])->assertUnprocessable()->assertJsonPath('code', 'wrong_code');
        $this->assertTrue((bool) $this->user->fresh()->must_reset_password);
    }

    public function test_the_card_is_activated_once_on_the_server_and_unlocks_the_qr_code(): void
    {
        // The company's package is paid: an active gym membership.
        $plan = \App\Models\MembershipPlan::forceCreate(['name' => 'Fit Basic', 'tier' => 'basic', 'monthly_fee_etb' => 3600]);
        $gym = \App\Models\Gym::forceCreate(['name' => 'Bole Gym', 'address' => 'Bole', 'tier' => 'basic', 'is_active' => true]);
        \App\Models\Membership::forceCreate(['employee_id' => $this->employee->id, 'gym_id' => $gym->id, 'plan_id' => $plan->id, 'status' => 'active', 'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addMonth()->toDateString()]);
        $this->employee->forceFill(['payment_status' => 'paid', 'paid_until' => now()->addMonth()->toDateString()])->save();
        Sanctum::actingAs($this->user);
        $this->getJson('/api/v1/mobile/profile')->assertJsonPath('data.card_activated', false);
        $this->getJson('/api/v1/mobile/qr-token')->assertForbidden()->assertJsonPath('code', 'card_not_activated');

        $this->postJson('/api/v1/mobile/membership/activate', ['member_number' => $this->employee->card_number])
            ->assertOk()->assertJsonPath('data.card_activated', true);

        $this->assertNotNull($this->employee->fresh()->card_activated_at);
        $this->getJson('/api/v1/mobile/profile')->assertJsonPath('data.card_activated', true);
        $this->getJson('/api/v1/mobile/qr-token')->assertJsonMissingPath('code');
    }

    private function signup(string $email)
    {
        return $this->postJson('/api/v1/auth/signup', ['email' => $email, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
    }

    private function code(): string
    {
        $code = '';
        Mail::assertSent(FitAccessNotificationMail::class, function ($m) use (&$code) {
            $code = $m->heading;

            return $m->hasTo('abebe@ethiotelecom.test') && $m->emailSubject === 'Your FitAccess confirmation code';
        });

        return $code;
    }
}
