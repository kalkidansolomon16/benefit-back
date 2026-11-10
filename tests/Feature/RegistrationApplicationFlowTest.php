<?php

namespace Tests\Feature;

use App\Mail\FitAccessMail;
use App\Models\Company;
use App\Models\CompanyApplication;
use App\Models\CompanyBranch;
use App\Models\Gym;
use App\Models\GymStaff;
use App\Models\PartnerApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Companies and gyms apply from the public forms; no login exists until an
 * admin approves, which creates the accounts with temporary passwords that are
 * emailed and must be changed at first sign-in.
 */
class RegistrationApplicationFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->admin = User::forceCreate([
            'name' => 'FitAccess Admin', 'email' => 'admin@flow.test', 'password' => Hash::make('x'),
            'role' => 'fitaccess_admin', 'is_active' => true,
        ]);
    }

    private function companyPayload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Flow Test Bank',
            'industry'     => 'banking',
            'company_size' => '51 – 200',
            'tin'          => '0012345678',
            'contact_name' => 'Abebe Kebede',
            'job_title'    => 'HR Manager',
            'email'        => 'hr@flowbank.test',
            'phone'        => '+251911000001',
            'branches'     => json_encode([[
                'branch_name' => 'Piassa', 'contact_person' => 'Sara T.', 'email' => 'piassa@flowbank.test',
                'city' => 'Addis Ababa', 'woreda' => 'Woreda 3',
            ]]),
        ], $overrides);
    }

    private function partnerPayload(array $overrides = []): array
    {
        return array_merge([
            'facility_name'  => 'Flow Fitness',
            'categories'     => json_encode(['gym']),
            'contact_person' => 'Dawit G.',
            'contact_phone'  => '+251911000002',
            'contact_email'  => 'owner@flowfit.test',
            'tin_number'     => '0098765432',
            'city'           => 'Addis Ababa',
            'woreda'         => 'Bole, Woreda 7',
            'weekday_open'   => '06:00',
            'weekday_close'  => '22:00',
            'max_capacity'   => 40,
            'has_permits'             => '1',
            'has_certified_equipment' => '1',
            'has_reception'           => '0',
        ], $overrides);
    }

    public function test_company_application_creates_no_account_and_sends_emails(): void
    {
        $res = $this->postJson('/api/v1/auth/register/company', $this->companyPayload());

        $res->assertCreated()->assertJsonPath('reference', fn($r) => str_starts_with($r, 'FA-C'));
        $this->assertSame(0, User::where('email', 'hr@flowbank.test')->count());
        $this->assertSame(0, User::where('email', 'piassa@flowbank.test')->count());
        $this->assertSame(0, Company::count());
        $this->assertDatabaseHas('company_applications', ['contact_email' => 'hr@flowbank.test', 'status' => 'pending', 'industry' => 'banking']);

        Mail::assertSent(FitAccessMail::class, fn($m) => $m->hasTo('hr@flowbank.test') && str_contains($m->emailSubject, 'registered under number'));
        Mail::assertSent(FitAccessMail::class, fn($m) => $m->hasTo('admin@flow.test') && str_contains($m->emailSubject, 'New employer application'));

        // A second application while the first is pending is refused
        $this->postJson('/api/v1/auth/register/company', $this->companyPayload())
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_company_application_requires_tin(): void
    {
        $this->postJson('/api/v1/auth/register/company', $this->companyPayload(['tin' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('tin');
    }

    public function test_partner_application_stores_standards_and_optional_website(): void
    {
        // Website is optional
        $this->postJson('/api/v1/auth/register/partner', $this->partnerPayload())->assertCreated();
        $this->assertDatabaseHas('partner_applications', [
            'contact_email' => 'owner@flowfit.test', 'website' => null,
            'has_permits' => 1, 'has_certified_equipment' => 1, 'has_reception' => 0,
        ]);

        // An invalid website and unanswered questions are refused
        $this->postJson('/api/v1/auth/register/partner', $this->partnerPayload([
            'contact_email' => 'other@flowfit.test',
            'website'       => 'not a website',
            'has_permits'   => null,
        ]))->assertStatus(422)->assertJsonValidationErrors(['website', 'has_permits']);

        // A valid website is kept
        $this->postJson('/api/v1/auth/register/partner', $this->partnerPayload([
            'contact_email' => 'third@flowfit.test',
            'website'       => 'https://www.flowfit.et',
        ]))->assertCreated();
        $this->assertDatabaseHas('partner_applications', ['contact_email' => 'third@flowfit.test', 'website' => 'https://www.flowfit.et']);
    }

    public function test_only_admins_can_review_applications(): void
    {
        $this->postJson('/api/v1/auth/register/company', $this->companyPayload())->assertCreated();
        $app = CompanyApplication::firstOrFail();

        $employee = User::forceCreate([
            'name' => 'Some Employee', 'email' => 'emp@flow.test', 'password' => Hash::make('x'),
            'role' => 'employee', 'is_active' => true,
        ]);
        Sanctum::actingAs($employee);

        $this->getJson('/api/v1/company-applications')->assertForbidden();
        $this->postJson("/api/v1/company-applications/{$app->id}/approve", ['tier' => 'basic'])->assertForbidden();
        $this->getJson('/api/v1/partner-applications')->assertForbidden();
        $this->assertSame('pending', $app->fresh()->status);
    }

    public function test_approving_company_creates_accounts_with_temporary_passwords(): void
    {
        $this->postJson('/api/v1/auth/register/company', $this->companyPayload())->assertCreated();
        $app = CompanyApplication::firstOrFail();

        Sanctum::actingAs($this->admin);
        $res = $this->postJson("/api/v1/company-applications/{$app->id}/approve", ['tier' => 'basic_plus'])->assertOk();

        $company = Company::where('contact_email', 'hr@flowbank.test')->firstOrFail();
        $this->assertTrue((bool) $company->is_active);
        $this->assertSame('approved', $app->fresh()->status);
        $this->assertSame($company->id, $app->fresh()->company_id);

        $hr = User::where('email', 'hr@flowbank.test')->firstOrFail();
        $this->assertSame('company_hr', $hr->role);
        $this->assertTrue((bool) $hr->is_active);
        $this->assertTrue((bool) $hr->must_reset_password);

        $branch = CompanyBranch::where('company_id', $company->id)->firstOrFail();
        $branchLogin = User::where('email', 'piassa@flowbank.test')->firstOrFail();
        $this->assertSame($branch->id, $branchLogin->company_branch_id);
        $this->assertTrue((bool) $branchLogin->must_reset_password);

        // Credentials returned once to the admin match what was stored
        $creds = collect($res->json('credentials'))->keyBy('email');
        $this->assertCount(2, $creds);
        $this->assertTrue(Hash::check($creds['hr@flowbank.test']['temp_password'], $hr->password));
        $this->assertTrue(Hash::check($creds['piassa@flowbank.test']['temp_password'], $branchLogin->password));

        Mail::assertSent(FitAccessMail::class, fn($m) => $m->hasTo('hr@flowbank.test') && $m->emailSubject === 'Your FitAccess account is ready');
        Mail::assertSent(FitAccessMail::class, fn($m) => $m->hasTo('piassa@flowbank.test') && $m->emailSubject === 'Your FitAccess account is ready');

        // Cannot be approved twice
        $this->postJson("/api/v1/company-applications/{$app->id}/approve", ['tier' => 'basic'])->assertStatus(422);
    }

    public function test_rejecting_company_informs_applicant(): void
    {
        $this->postJson('/api/v1/auth/register/company', $this->companyPayload())->assertCreated();
        $app = CompanyApplication::firstOrFail();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/company-applications/{$app->id}/reject", ['reason' => 'TIN could not be verified'])->assertOk();

        $this->assertSame('rejected', $app->fresh()->status);
        $this->assertSame(0, User::where('email', 'hr@flowbank.test')->count());
        Mail::assertSent(FitAccessMail::class, fn($m) => $m->hasTo('hr@flowbank.test')
            && in_array('Reason: TIN could not be verified', $m->paragraphs, true));
    }

    public function test_partner_application_and_approval(): void
    {
        $res = $this->postJson('/api/v1/auth/register/partner', $this->partnerPayload())->assertCreated();
        $this->assertStringStartsWith('FA-P', $res->json('reference'));
        $this->assertSame(0, User::where('email', 'owner@flowfit.test')->count());

        $app = PartnerApplication::firstOrFail();
        $this->assertNull($app->user_id);
        Mail::assertSent(FitAccessMail::class, fn($m) => $m->hasTo('owner@flowfit.test'));
        Mail::assertSent(FitAccessMail::class, fn($m) => $m->hasTo('admin@flow.test') && str_contains($m->emailSubject, 'New partner application'));

        Sanctum::actingAs($this->admin);
        $approve = $this->postJson("/api/v1/partner-applications/{$app->id}/approve", ['tier' => 'basic'])->assertOk();

        $owner = User::where('email', 'owner@flowfit.test')->firstOrFail();
        $this->assertSame('gym_partner', $owner->role);
        $this->assertTrue((bool) $owner->must_reset_password);
        $this->assertSame($owner->id, $app->fresh()->user_id);

        $gym = Gym::where('name', 'Flow Fitness')->firstOrFail();
        $staff = GymStaff::where('user_id', $owner->id)->where('gym_id', $gym->id)->firstOrFail();
        $this->assertTrue((bool) $staff->must_change_password);

        $this->assertTrue(Hash::check($approve->json('credentials.0.temp_password'), $owner->password));
        Mail::assertSent(FitAccessMail::class, fn($m) => $m->hasTo('owner@flowfit.test') && $m->emailSubject === 'Your FitAccess account is ready');
    }

    public function test_rejecting_partner_without_login_informs_applicant(): void
    {
        $this->postJson('/api/v1/auth/register/partner', $this->partnerPayload())->assertCreated();
        $app = PartnerApplication::firstOrFail();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/partner-applications/{$app->id}/reject", ['reason' => 'Outside service area'])->assertOk();

        $this->assertSame('rejected', $app->fresh()->status);
        Mail::assertSent(FitAccessMail::class, fn($m) => $m->hasTo('owner@flowfit.test')
            && in_array('Reason: Outside service area', $m->paragraphs, true));
    }
}
