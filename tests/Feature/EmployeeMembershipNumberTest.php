<?php

namespace Tests\Feature;

use App\Mail\FitAccessNotificationMail;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Gym;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Support\CardNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Employee-only release: the company's package emails the 12-digit number; the app checks it once. */
class EmployeeMembershipNumberTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Company $company;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::flush();
        Carbon::setTestNow('2026-11-12 09:00:00');

        $this->admin = User::forceCreate(['name' => 'Admin', 'email' => 'admin@num.test', 'password' => Hash::make('x'), 'role' => 'fitaccess_admin', 'is_active' => true]);
        MembershipPlan::create(['name' => 'Fit Basic', 'tier' => 'basic', 'monthly_fee_etb' => 3600, 'is_active' => true]);
        Gym::forceCreate(['name' => 'Bole Gym', 'address' => 'Bole', 'tier' => 'basic', 'is_active' => true]);
        $this->company = Company::forceCreate([
            'name' => 'Ethio Telecom', 'industry' => 'telecom', 'contact_email' => 'hr@num.test',
            'contact_person' => 'Hana HR', 'is_active' => true, 'company_status' => 'active', 'billing_cycle_months' => 1,
        ]);
        $u = User::forceCreate(['name' => 'Abebe Kebede', 'email' => 'abebe@num.test', 'password' => Hash::make('x'), 'role' => 'employee', 'is_active' => true, 'company_id' => $this->company->id]);
        $this->employee = Employee::create([
            'user_id' => $u->id, 'company_id' => $this->company->id, 'fan_number' => 'FAN-1',
            'level' => 'staff', 'registration_status' => 'approved', 'is_enrolled' => true, 'payment_status' => 'unpaid',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_paying_the_package_emails_the_membership_number_once(): void
    {
        $this->buyAndPay();

        $number = $this->employee->fresh()->card_number;
        $this->assertTrue(CardNumber::normalize($number) === $number, 'a valid 12-digit number');
        Mail::assertSent(FitAccessNotificationMail::class, fn ($m) => $m->hasTo('abebe@num.test')
            && $m->emailSubject === 'Your FitAccess membership number'
            && $m->heading === CardNumber::format($number)
            && str_contains($m->message, 'Ethio Telecom'));

        // Renewing while still covered sends no new number email.
        Mail::fake();
        Carbon::setTestNow('2026-12-01 09:00:00');
        $this->buyAndPay();
        Mail::assertNotSent(FitAccessNotificationMail::class, fn ($m) => $m->emailSubject === 'Your FitAccess membership number');
    }

    public function test_the_app_checks_the_number_and_limits_guesses(): void
    {
        $number = $this->employee->card_number;
        Sanctum::actingAs($this->employee->user);

        $this->postJson('/api/v1/mobile/membership/activate', ['member_number' => CardNumber::format($number)])
            ->assertOk()->assertJsonPath('data.company_name', 'Ethio Telecom');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/mobile/membership/activate', ['member_number' => '1234 5678 9015'])
                ->assertUnprocessable()->assertJsonPath('code', 'wrong_number');
        }
        $this->postJson('/api/v1/mobile/membership/activate', ['member_number' => $number])
            ->assertStatus(429)->assertJsonPath('code', 'too_many');
    }

    public function test_accounts_without_a_company_membership_are_told_so(): void
    {
        $member = User::forceCreate(['name' => 'M', 'email' => 'm@num.test', 'password' => Hash::make('x'), 'role' => 'member', 'is_active' => true]);
        Sanctum::actingAs($member);
        $this->postJson('/api/v1/mobile/membership/activate', ['member_number' => '1234 5678 9015'])
            ->assertUnprocessable()->assertJsonPath('code', 'no_membership');
    }

    private function buyAndPay(): void
    {
        Sanctum::actingAs($this->admin);
        $invoiceId = $this->postJson('/api/v1/admin/billing/invoices/generate', ['company_id' => $this->company->id])->assertCreated()->json('invoice.id');
        $payment = BillingPayment::forceCreate([
            'billing_invoice_id' => $invoiceId, 'company_id' => $this->company->id,
            'amount' => BillingInvoice::find($invoiceId)->total_amount, 'status' => 'pending', 'submitted_at' => now(),
        ]);
        $this->postJson("/api/v1/admin/billing/payments/{$payment->id}/verify")->assertOk();
    }
}
