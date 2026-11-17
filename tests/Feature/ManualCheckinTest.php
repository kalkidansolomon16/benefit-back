<?php

namespace Tests\Feature;

use App\Models\AdminNotification;
use App\Models\Checkin;
use App\Models\Gym;
use App\Models\GymStaff;
use App\Models\MembershipPlan;
use App\Models\MemberVerification;
use App\Models\MobileSubscription;
use App\Models\Notification;
use App\Models\User;
use App\Support\CardNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * v2 manual check-in: member number plus a national ID check by staff,
 * limited per month, and the member is notified each time.
 */
class ManualCheckinTest extends TestCase
{
    use RefreshDatabase;

    private Gym $gym;
    private User $staff;
    private User $member;
    private string $number;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->gym = Gym::forceCreate(['name' => 'Kuriftu Gym', 'address' => 'Bole', 'tier' => 'basic', 'is_active' => true]);
        $this->staff = $this->user('gym_staff', ['name' => 'Meron']);
        GymStaff::forceCreate(['user_id' => $this->staff->id, 'gym_id' => $this->gym->id, 'role' => 'staff', 'is_active' => true, 'must_change_password' => false]);

        $this->number = CardNumber::generate();
        $this->member = $this->user('member', ['name' => 'Abebe Kebede', 'member_number' => $this->number]);
        $plan = MembershipPlan::forceCreate(['name' => 'Fit Plus', 'tier' => 'basic', 'monthly_fee_etb' => 7200]);
        MobileSubscription::forceCreate(['user_id' => $this->member->id, 'plan_id' => $plan->id, 'status' => 'active', 'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addMonth()->toDateString()]);
        $this->verification(MemberVerification::PENDING);
    }

    public function test_lookup_shows_reception_the_member(): void
    {
        $this->asStaff()->getJson("/api/v1/partner/members/{$this->number}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Abebe Kebede')
            ->assertJsonPath('data.access', 'ok')
            ->assertJsonPath('data.manual_left', 2)
            ->assertJsonPath('data.has_photo', true);
    }

    public function test_unknown_or_mistyped_numbers_are_not_found(): void
    {
        $typo = substr($this->number, 0, 11).(((int) substr($this->number, -1) + 1) % 10);
        $this->asStaff()->getJson("/api/v1/partner/members/{$typo}")->assertNotFound();
        $this->asStaff()->getJson('/api/v1/partner/members/123')->assertNotFound();
    }

    public function test_members_cannot_use_the_staff_endpoints(): void
    {
        $this->as($this->member)->getJson("/api/v1/partner/members/{$this->number}")->assertForbidden();
        $this->as($this->member)->get("/api/v1/partner/members/{$this->number}/photo")->assertForbidden();
        $this->as($this->member)->postJson('/api/v1/partner/manual-checkins', ['member_number' => $this->number, 'id_checked' => true])->assertForbidden();
    }

    public function test_confirm_needs_the_id_check_and_records_a_manual_visit(): void
    {
        $this->asStaff()->postJson('/api/v1/partner/manual-checkins', ['member_number' => $this->number])->assertUnprocessable();

        $this->asStaff()->postJson('/api/v1/partner/manual-checkins', ['member_number' => $this->number, 'id_checked' => true])
            ->assertCreated()
            ->assertJsonPath('data.checked_by', 'Meron')
            ->assertJsonPath('data.manual_left', 1);

        $this->assertDatabaseHas('checkins', ['user_id' => $this->member->id, 'gym_id' => $this->gym->id, 'method' => 'manual', 'recorded_by' => 'Meron']);
        $note = Notification::where('user_id', $this->member->id)->where('type', 'manual_checkin')->first();
        $this->assertNotNull($note, 'the member is told');
        $this->assertSame('Kuriftu Gym', $note->data['gym']);
        // The app opens "Was this you?" from these details.
        $this->as($this->member)->getJson('/api/v1/mobile/notifications')
            ->assertJsonPath('data.0.type', 'manual_checkin')
            ->assertJsonPath('data.0.data.checkin_id', $note->data['checkin_id']);
        $this->assertSame(MemberVerification::VERIFIED, MemberVerification::first()->status, 'staff checked the ID: first-visit verification');
    }

    public function test_monthly_limit(): void
    {
        foreach ([1, 2] as $_) {
            $this->asStaff()->postJson('/api/v1/partner/manual-checkins', ['member_number' => $this->number, 'id_checked' => true])->assertCreated();
            $this->travel(13)->hours();
        }

        $this->asStaff()->getJson("/api/v1/partner/members/{$this->number}")->assertJsonPath('data.access', 'limit_reached');
        $this->asStaff()->postJson('/api/v1/partner/manual-checkins', ['member_number' => $this->number, 'id_checked' => true])
            ->assertUnprocessable()->assertJsonPath('access', 'limit_reached');
        $this->assertSame(2, Checkin::count());
    }

    public function test_the_12_hour_rule_still_applies(): void
    {
        $this->asStaff()->postJson('/api/v1/partner/manual-checkins', ['member_number' => $this->number, 'id_checked' => true])->assertCreated();
        $this->asStaff()->postJson('/api/v1/partner/manual-checkins', ['member_number' => $this->number, 'id_checked' => true])
            ->assertUnprocessable()->assertJsonPath('access', 'cooldown');
    }

    public function test_members_without_a_photo_cannot_be_checked_in_by_hand(): void
    {
        MemberVerification::query()->delete();

        $this->asStaff()->getJson("/api/v1/partner/members/{$this->number}")->assertJsonPath('data.access', 'not_verified');
        $this->asStaff()->postJson('/api/v1/partner/manual-checkins', ['member_number' => $this->number, 'id_checked' => true])->assertUnprocessable();
    }

    public function test_mismatch_pauses_manual_checkins_and_alerts_admins(): void
    {
        $this->asStaff()->postJson('/api/v1/partner/manual-checkins/mismatch', ['member_number' => $this->number, 'reason' => 'face'])->assertOk();

        $this->assertNotNull($this->member->fresh()->manual_checkins_paused_at);
        $this->assertSame(1, AdminNotification::where('type', 'manual_checkin_flag')->count());
        $this->asStaff()->getJson("/api/v1/partner/members/{$this->number}")->assertJsonPath('data.access', 'paused');
    }

    public function test_member_can_report_a_manual_checkin_that_was_not_them(): void
    {
        $this->asStaff()->postJson('/api/v1/partner/manual-checkins', ['member_number' => $this->number, 'id_checked' => true])->assertCreated();
        $checkin = Checkin::first();

        $other = $this->user('member');
        $this->as($other)->postJson("/api/v1/mobile/manual-checkins/{$checkin->id}/report")->assertNotFound();

        $this->as($this->member)->postJson("/api/v1/mobile/manual-checkins/{$checkin->id}/report")->assertOk();
        $this->assertNotNull($this->member->fresh()->manual_checkins_paused_at);
        $this->assertSame(1, AdminNotification::where('type', 'manual_checkin_flag')->count());
    }

    public function test_photo_is_served_to_staff_without_caching(): void
    {
        $this->asStaff()->get("/api/v1/partner/members/{$this->number}/photo")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    private function verification(string $status): void
    {
        $path = UploadedFile::fake()->image('s.jpg', 600, 800)->store('verifications/'.$this->member->id, 'local');
        MemberVerification::create(['user_id' => $this->member->id, 'photo_path' => $path, 'status' => $status]);
    }

    private function asStaff(): static
    {
        return $this->as($this->staff);
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
