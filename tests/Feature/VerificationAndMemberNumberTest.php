<?php

namespace Tests\Feature;

use App\Models\MembershipPlan;
use App\Models\MemberVerification;
use App\Models\MobileSubscription;
use App\Models\User;
use App\Support\CardNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** v2: member numbers for individual members and selfie verification. */
class VerificationAndMemberNumberTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->member = $this->user('member');
    }

    public function test_member_number_arrives_with_the_first_plan(): void
    {
        $plan = MembershipPlan::forceCreate(['name' => 'Fit Basic', 'tier' => 'basic', 'monthly_fee_etb' => 3600]);
        $this->as($this->member);

        $number = $this->postJson('/api/v1/mobile/subscription', [
            'package_id' => $plan->id, 'billing_cycle' => 'monthly', 'payment_reference' => 'tx-1', 'amount_paid' => 3600,
        ])->assertCreated()->json('data.member_number');

        $this->assertNotNull(CardNumber::normalize($number), 'valid 12-digit Luhn number');
        $this->assertSame($number, $this->member->fresh()->member_number);
        $this->as($this->member);
        $this->getJson('/api/v1/mobile/profile')->assertJsonPath('data.member_number', $number);

        // Renewing keeps the same number.
        $this->as($this->member);
        $this->postJson('/api/v1/mobile/subscription', [
            'package_id' => $plan->id, 'billing_cycle' => 'monthly', 'payment_reference' => 'tx-2', 'amount_paid' => 3600,
        ])->assertJsonPath('data.member_number', $number);
    }

    public function test_selfie_is_stored_privately_and_marks_the_member_pending(): void
    {
        $this->as($this->member);
        $this->getJson('/api/v1/mobile/profile')->assertJsonPath('data.verification_status', 'not_started')->assertJsonPath('data.verification_required', false);

        $this->as($this->member);
        $this->post('/api/v1/mobile/verification', ['photo' => UploadedFile::fake()->image('selfie.jpg', 800, 1000)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $v = MemberVerification::first();
        Storage::disk('local')->assertExists($v->photo_path);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'never on the public disk');
        $this->assertArrayNotHasKey('photo_path', $v->toArray());

        $this->as($this->member);
        $this->getJson('/api/v1/mobile/profile')->assertJsonPath('data.verification_status', 'pending');
        $this->as($this->member);
        $this->get('/api/v1/mobile/verification/photo')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_upload_must_be_an_image_of_reasonable_size(): void
    {
        $this->as($this->member);
        $this->post('/api/v1/mobile/verification', ['photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->as($this->member);
        $this->post('/api/v1/mobile/verification', ['photo' => UploadedFile::fake()->image('tiny.jpg', 100, 100)], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_admin_review_and_access_control(): void
    {
        $v = $this->submitFor($this->member);
        $other = $this->user('member');

        // Members can't see the queue or other people's photos.
        $this->as($other);
        $this->getJson('/api/v1/verifications')->assertForbidden();
        $this->as($other);
        $this->getJson("/api/v1/verifications/{$v->id}/photo")->assertForbidden();
        $this->as($other);
        $this->getJson('/api/v1/mobile/verification/photo')->assertNotFound();

        $admin = $this->user('fitaccess_admin');
        $this->as($admin);
        $this->getJson('/api/v1/verifications')->assertOk()->assertJsonPath('data.0.id', $v->id);
        $this->as($admin);
        $this->postJson("/api/v1/verifications/{$v->id}/reject", ['reason' => 'Too dark to match your face'])->assertOk();

        $this->as($this->member);
        $this->getJson('/api/v1/mobile/profile')
            ->assertJsonPath('data.verification_status', 'rejected')
            ->assertJsonPath('data.verification_rejection_reason', 'Too dark to match your face');
    }

    public function test_a_pending_reverification_keeps_an_earlier_approval(): void
    {
        $first = $this->submitFor($this->member);
        $first->forceFill(['status' => MemberVerification::VERIFIED])->save();

        $this->submitFor($this->member);

        $this->as($this->member);
        $this->getJson('/api/v1/mobile/verification')->assertJsonPath('data.status', 'verified');
    }

    public function test_optional_verification_gate_on_check_in_codes(): void
    {
        $plan = MembershipPlan::forceCreate(['name' => 'Fit Basic', 'tier' => 'basic', 'monthly_fee_etb' => 3600]);
        MobileSubscription::forceCreate(['user_id' => $this->member->id, 'plan_id' => $plan->id, 'status' => 'active', 'start_date' => now()->toDateString(), 'end_date' => now()->addMonth()->toDateString()]);

        $this->as($this->member);
        $this->getJson('/api/v1/mobile/qr-token')->assertOk(); // off by default

        config(['fitaccess.require_verification' => true]);
        $this->as($this->member);
        $this->getJson('/api/v1/mobile/qr-token')->assertForbidden()->assertJsonPath('code', 'verification_required');

        $this->submitFor($this->member);
        $this->as($this->member);
        $this->getJson('/api/v1/mobile/qr-token')->assertOk();
    }

    public function test_employees_and_members_share_one_number_space(): void
    {
        $n = CardNumber::generate();
        DB::table('users')->where('id', $this->member->id)->update(['member_number' => $n]);

        for ($i = 0; $i < 25; $i++) {
            $this->assertNotSame($n, CardNumber::generate());
        }
    }

    private function submitFor(User $u): MemberVerification
    {
        $this->as($u);
        $this->post('/api/v1/mobile/verification', ['photo' => UploadedFile::fake()->image('s.jpg', 800, 1000)], ['Accept' => 'application/json'])->assertCreated();

        return MemberVerification::where('user_id', $u->id)->latest('id')->first();
    }

    private function as(User $u): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);
    }

    private function user(string $role): User
    {
        return User::forceCreate([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => uniqid($role).'@fitaccess.test',
            'password' => Hash::make('password'),
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
