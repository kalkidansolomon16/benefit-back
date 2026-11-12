<?php

namespace Tests\Feature;

use App\Mail\FitAccessMail;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Gym;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Invoices pay for a coverage period (1, 3, 6 or 12 months). Paying one gives
 * the covered employees access until the period's last day; after that, check-in
 * is refused until the company pays the next invoice.
 */
class BillingCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Company $company;
    private User $hr;
    /** @var Employee[] */
    private array $employees = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow('2026-11-10 09:00:00');

        $this->admin = User::forceCreate([
            'name' => 'FitAccess Admin', 'email' => 'admin@cov.test', 'password' => Hash::make('x'),
            'role' => 'fitaccess_admin', 'is_active' => true,
        ]);

        MembershipPlan::create(['name' => 'Fit Basic', 'tier' => 'basic', 'monthly_fee_etb' => 3600, 'is_active' => true]);
        Gym::forceCreate(['name' => 'Bole Gym', 'address' => 'Bole', 'tier' => 'basic', 'is_active' => true]);

        $this->company = Company::forceCreate([
            'name' => 'Coverage Bank', 'industry' => 'banking', 'contact_email' => 'hr@cov.test',
            'contact_person' => 'Hana HR', 'is_active' => true, 'company_status' => 'active',
            'billing_cycle_months' => 3,
        ]);
        $this->hr = User::forceCreate([
            'name' => 'Hana HR', 'email' => 'hr@cov.test', 'password' => Hash::make('x'),
            'role' => 'company_hr', 'is_active' => true, 'company_id' => $this->company->id,
        ]);

        foreach ([1, 2] as $i) {
            $u = User::forceCreate([
                'name' => "Employee {$i}", 'email' => "emp{$i}@cov.test", 'password' => Hash::make('x'),
                'role' => 'employee', 'is_active' => true,
            ]);
            $this->employees[] = Employee::create([
                'user_id' => $u->id, 'company_id' => $this->company->id, 'fan_number' => "FAN-COV-{$i}",
                'level' => 'staff', 'registration_status' => 'approved', 'is_enrolled' => true,
                'payment_status' => 'unpaid',
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payInvoice(int $invoiceId): void
    {
        $payment = BillingPayment::forceCreate([
            'billing_invoice_id' => $invoiceId, 'company_id' => $this->company->id,
            'amount' => BillingInvoice::find($invoiceId)->total_amount, 'status' => 'pending', 'submitted_at' => now(),
        ]);
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/admin/billing/payments/{$payment->id}/verify")->assertOk();
    }

    public function test_invoice_uses_company_preference_and_charges_every_month(): void
    {
        Sanctum::actingAs($this->admin);

        $preview = $this->postJson('/api/v1/admin/billing/invoices/preview', ['company_id' => $this->company->id])
            ->assertOk()
            ->assertJsonPath('preferred_months', 3)
            ->assertJsonPath('period_months', 3)
            ->assertJsonPath('period_start', '2026-11-10')
            ->assertJsonPath('period_end', '2027-02-09')
            ->assertJsonPath('employees', 2);
        // 2 employees × ETB 3,600 × 3 months
        $this->assertEquals(21600, $preview->json('total'));

        $res = $this->postJson('/api/v1/admin/billing/invoices/generate', ['company_id' => $this->company->id])
            ->assertCreated()
            ->assertJsonPath('invoice.period_months', 3)
            ->assertJsonPath('invoice.period_end', '2027-02-09')
            ->assertJsonPath('invoice.items.0.months', 3);

        $invoice = BillingInvoice::find($res->json('invoice.id'));
        $this->assertEquals(21600, (float) $invoice->total_amount);
        $this->assertSame(2, $invoice->coveredEmployees()->count());

        // The same employees are not billed twice while that invoice is unpaid
        $this->postJson('/api/v1/admin/billing/invoices/generate', ['company_id' => $this->company->id])
            ->assertStatus(422);
    }

    public function test_admin_can_choose_another_length_for_one_invoice(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/admin/billing/invoices/preview', [
            'company_id' => $this->company->id, 'period_months' => 1, 'period_start' => '2026-12-01',
        ])->assertOk()->assertJsonPath('period_end', '2026-12-31')->assertJsonPath('total', 7200);

        $this->postJson('/api/v1/admin/billing/invoices/preview', ['company_id' => $this->company->id, 'period_months' => 2])
            ->assertStatus(422);
    }

    public function test_paying_gives_access_until_the_period_end_then_it_stops(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/v1/admin/billing/invoices/generate', ['company_id' => $this->company->id])->json('invoice.id');
        $this->postJson("/api/v1/admin/billing/invoices/{$id}/send")->assertOk();
        Mail::assertSent(FitAccessMail::class, fn($m) => $m->hasTo('hr@cov.test') && str_contains($m->emailSubject, '10 Nov 2026 – 9 Feb 2027'));

        $this->payInvoice($id);

        $emp = $this->employees[0]->fresh();
        $this->assertSame('2027-02-09', $emp->paid_until->toDateString());
        $this->assertSame('paid', $emp->payment_status);
        $this->assertTrue($emp->hasPaidAccess());
        $this->assertSame('2027-02-09', Membership::where('employee_id', $emp->id)->first()->end_date->toDateString());

        // Employee may pick a gym today
        Sanctum::actingAs($emp->user);
        $gym = Gym::first();
        $this->postJson('/api/v1/employee/select-gym', ['gym_id' => $gym->id])->assertCreated();

        // The public card check says active, with the paid-until date
        $this->postJson('/api/v1/public/card-status', ['card_number' => $emp->card_number])
            ->assertJsonPath('status', 'active')->assertJsonPath('valid_until', '2027-02-09');

        // The day after the paid period: access stops
        Carbon::setTestNow('2027-02-10 09:00:00');
        $emp = $emp->fresh();
        $this->assertFalse($emp->hasPaidAccess());
        $this->assertSame('expired', $emp->accessStatus());

        Sanctum::actingAs($emp->user);
        $this->postJson('/api/v1/employee/select-gym', ['gym_id' => $gym->id])
            ->assertStatus(403)->assertJsonPath('reason', 'not_paid');
        $this->getJson('/api/v1/employee/checkin-token')->assertJsonPath('access_ended', true);
        $this->postJson('/api/v1/public/card-status', ['card_number' => $emp->card_number])
            ->assertJsonPath('status', 'inactive');

        // Daily housekeeping marks it too
        Artisan::call('billing:coverage', ['--no-mail' => true]);
        $this->assertSame('unpaid', $emp->fresh()->payment_status);
        $this->assertSame('expired', Membership::where('employee_id', $emp->id)->first()->status);
    }

    public function test_next_invoice_starts_after_the_paid_period_and_renews_access(): void
    {
        Sanctum::actingAs($this->admin);
        $first = $this->postJson('/api/v1/admin/billing/invoices/generate', ['company_id' => $this->company->id])->json('invoice.id');
        $this->payInvoice($first);

        // Covered until 9 Feb: a period starting before then has nobody to bill
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/admin/billing/invoices/preview', ['company_id' => $this->company->id, 'period_start' => '2027-01-01'])
            ->assertOk()->assertJsonPath('employees', 0)->assertJsonPath('already_covered', 2);

        // The suggested start is the day after coverage ends
        $this->postJson('/api/v1/admin/billing/invoices/preview', ['company_id' => $this->company->id])
            ->assertOk()->assertJsonPath('period_start', '2027-02-10')->assertJsonPath('period_end', '2027-05-09');

        $second = $this->postJson('/api/v1/admin/billing/invoices/generate', ['company_id' => $this->company->id])
            ->assertCreated()->json('invoice.id');
        $this->payInvoice($second);

        $this->assertSame('2027-05-09', $this->employees[1]->fresh()->paid_until->toDateString());
        Carbon::setTestNow('2027-03-01 09:00:00');
        $this->assertTrue($this->employees[1]->fresh()->hasPaidAccess());
    }

    public function test_hr_sees_coverage_and_sets_billing_preference(): void
    {
        Sanctum::actingAs($this->hr);
        $this->getJson('/api/v1/hr/billing/coverage')
            ->assertOk()
            ->assertJsonPath('billing_cycle_months', 3)
            ->assertJsonPath('employees', 2)
            ->assertJsonPath('not_paid_yet', 2)
            ->assertJsonPath('covered', 0);

        $this->putJson('/api/v1/hr/billing/preference', ['billing_cycle_months' => 6])->assertOk();
        $this->assertSame(6, $this->company->fresh()->billing_cycle_months);
        $this->putJson('/api/v1/hr/billing/preference', ['billing_cycle_months' => 5])->assertStatus(422);
    }

    public function test_only_billing_staff_can_use_admin_billing(): void
    {
        $employee = $this->employees[0]->user;
        Sanctum::actingAs($employee);
        $this->postJson('/api/v1/admin/billing/invoices/generate', ['company_id' => $this->company->id])->assertForbidden();
        $this->getJson('/api/v1/admin/billing/invoices')->assertForbidden();

        Sanctum::actingAs($this->hr);
        $this->getJson('/api/v1/admin/billing/pending-payments')->assertForbidden();
    }

    public function test_renewal_reminder_is_emailed_before_the_period_ends(): void
    {
        $this->employees[0]->update(['paid_until' => '2026-11-17', 'payment_status' => 'paid']);
        config(['fitaccess.admin_notification_emails' => ['team@cov.test']]);

        Artisan::call('billing:coverage');

        Mail::assertSent(FitAccessMail::class, fn($m) => $m->hasTo('hr@cov.test') && str_contains($m->emailSubject, 'ends on 17 Nov 2026'));
        Mail::assertSent(FitAccessMail::class, fn($m) => $m->hasTo('team@cov.test') && str_contains($m->emailSubject, 'Renewal invoices due'));
    }
}
