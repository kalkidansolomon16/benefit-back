<?php

namespace Tests\Feature;

use App\Mail\FitAccessNotificationMail;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** v2: linking a company membership, contact changes with a code, corrections. */
class LinkAndProfileChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private User $hrAccount;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::flush();

        $company = Company::forceCreate(['name' => 'Ethio Telecom', 'contact_email' => 'hr@et.test', 'contact_person' => 'HR', 'contact_phone' => '1', 'address' => 'a', 'city' => 'Addis Ababa']);
        $this->me = $this->user('member', ['email' => 'abebe@gmail.test', 'name' => 'Abebe Kebede']);
        $this->hrAccount = $this->user('employee', ['email' => 'abebe@ethiotelecom.test', 'must_reset_password' => true, 'company_id' => $company->id]);
        $this->employee = Employee::forceCreate(['user_id' => $this->hrAccount->id, 'company_id' => $company->id, 'fan_number' => '123456789012', 'is_enrolled' => true]);
        $this->employee->refresh();
    }

    // ── Link a company membership ───────────────────────────────────────

    public function test_link_sends_a_code_to_the_company_email_and_moves_the_membership(): void
    {
        $this->as($this->me)->postJson('/api/v1/mobile/link/lookup', ['member_number' => $this->employee->card_number])
            ->assertOk()
            ->assertJsonPath('data.masked_contact', 'a••••••@ethiotelecom.test');

        $code = $this->lastCode('abebe@ethiotelecom.test');

        $this->as($this->me)->postJson('/api/v1/mobile/link/confirm', ['member_number' => $this->employee->card_number, 'code' => $code === '000000' ? '111111' : '000000'])
            ->assertUnprocessable()->assertJsonPath('code', 'wrong_code');

        $this->as($this->me)->postJson('/api/v1/mobile/link/confirm', ['member_number' => $this->employee->card_number, 'code' => $code])
            ->assertOk()->assertJsonPath('data.company_name', 'Ethio Telecom');

        $this->assertSame($this->me->id, $this->employee->fresh()->user_id);
        $this->assertSame('employee', $this->me->fresh()->role);
        $this->assertFalse((bool) $this->hrAccount->fresh()->is_active, 'unused HR account switched off');
        $this->as($this->me)->getJson('/api/v1/mobile/profile')->assertJsonPath('data.company_name', 'Ethio Telecom');
    }

    public function test_an_account_already_in_use_cannot_be_linked(): void
    {
        $this->hrAccount->forceFill(['must_reset_password' => false])->save();

        $this->as($this->me)->postJson('/api/v1/mobile/link/lookup', ['member_number' => $this->employee->card_number])
            ->assertStatus(409)->assertJsonPath('code', 'already_linked');
        Mail::assertNothingSent();
    }

    public function test_unknown_numbers_and_too_many_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->as($this->me)->postJson('/api/v1/mobile/link/lookup', ['member_number' => '1234 5678 9012'])->assertNotFound();
        }
        $this->as($this->me)->postJson('/api/v1/mobile/link/lookup', ['member_number' => $this->employee->card_number])
            ->assertStatus(429)->assertJsonPath('code', 'too_many');
    }

    public function test_codes_stop_working_after_five_wrong_tries(): void
    {
        $this->as($this->me)->postJson('/api/v1/mobile/link/lookup', ['member_number' => $this->employee->card_number])->assertOk();
        $code = $this->lastCode('abebe@ethiotelecom.test');
        $wrong = $code === '999999' ? '888888' : '999999';

        for ($i = 0; $i < 5; $i++) {
            $this->as($this->me)->postJson('/api/v1/mobile/link/confirm', ['member_number' => $this->employee->card_number, 'code' => $wrong]);
        }
        $this->as($this->me)->postJson('/api/v1/mobile/link/confirm', ['member_number' => $this->employee->card_number, 'code' => $code])
            ->assertUnprocessable()->assertJsonPath('code', 'code_expired');
    }

    // ── Contact change ──────────────────────────────────────────────────

    public function test_email_change_is_confirmed_by_a_code_sent_to_the_new_address(): void
    {
        $this->as($this->me)->postJson('/api/v1/mobile/profile/contact', ['kind' => 'email', 'value' => 'Abebe.K@Outlook.test'])->assertOk();
        $code = $this->lastCode('abebe.k@outlook.test');

        $this->as($this->me)->postJson('/api/v1/mobile/profile/contact/verify', ['kind' => 'email', 'value' => 'abebe.k@outlook.test', 'code' => $code])->assertOk();

        $this->assertSame('abebe.k@outlook.test', $this->me->fresh()->email);
        Mail::assertSent(FitAccessNotificationMail::class, fn ($m) => $m->hasTo('abebe@gmail.test') && $m->emailSubject === 'Your FitAccess email was changed');
    }

    public function test_a_code_only_confirms_the_value_it_was_sent_for(): void
    {
        $this->as($this->me)->postJson('/api/v1/mobile/profile/contact', ['kind' => 'email', 'value' => 'new@outlook.test'])->assertOk();
        $code = $this->lastCode('new@outlook.test');

        $this->as($this->me)->postJson('/api/v1/mobile/profile/contact/verify', ['kind' => 'email', 'value' => 'attacker@evil.test', 'code' => $code])->assertUnprocessable();
        $this->assertSame('abebe@gmail.test', $this->me->fresh()->email);
    }

    public function test_email_already_used_is_refused(): void
    {
        $this->as($this->me)->postJson('/api/v1/mobile/profile/contact', ['kind' => 'email', 'value' => 'abebe@ethiotelecom.test'])->assertUnprocessable();
    }

    public function test_phone_change_needs_an_sms_provider(): void
    {
        $this->as($this->me)->postJson('/api/v1/mobile/profile/contact', ['kind' => 'phone', 'value' => '+251911234412'])
            ->assertStatus(503)->assertJsonPath('code', 'sms_unavailable');
    }

    public function test_old_edit_endpoints_are_closed(): void
    {
        $this->as($this->me)->patchJson('/api/v1/mobile/profile', ['name' => 'Someone Else'])->assertStatus(410);
        $this->assertSame('Abebe Kebede', $this->me->fresh()->name);
        $this->as($this->me)->postJson('/api/v1/mobile/profile/photo', [])->assertStatus(410);
    }

    // ── Corrections ─────────────────────────────────────────────────────

    public function test_correction_request_and_admin_approval(): void
    {
        $this->as($this->me)->postJson('/api/v1/mobile/profile/corrections', ['field' => 'name', 'requested_value' => 'Abebe Kebede Alemu', 'note' => 'grandfather’s name missing'])
            ->assertCreated();
        $this->as($this->me)->getJson('/api/v1/mobile/profile')->assertJsonPath('data.pending_corrections.0.requested_value', 'Abebe Kebede Alemu');

        $id = DB::table('profile_corrections')->value('id');
        $this->as($this->me)->postJson("/api/v1/profile-corrections/{$id}/approve")->assertForbidden();

        $this->as($this->user('fitaccess_admin'))->postJson("/api/v1/profile-corrections/{$id}/approve")->assertOk();
        $this->assertSame('Abebe Kebede Alemu', $this->me->fresh()->name);
    }

    public function test_employees_correct_their_name_through_hr(): void
    {
        $this->as($this->hrAccount)->postJson('/api/v1/mobile/profile/corrections', ['field' => 'name', 'requested_value' => 'X'])
            ->assertUnprocessable()->assertJsonPath('code', 'managed_by_company');
    }

    private function lastCode(string $to): string
    {
        $code = null;
        Mail::assertSent(FitAccessNotificationMail::class, function ($m) use ($to, &$code) {
            if ($m->hasTo($to) && $m->emailSubject === 'Your FitAccess confirmation code') {
                $code = $m->heading;

                return true;
            }

            return false;
        });

        return $code;
    }

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);

        return $this;
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
