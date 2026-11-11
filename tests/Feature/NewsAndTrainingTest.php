<?php

namespace Tests\Feature;

use App\Models\MembershipPlan;
use App\Models\MobileSubscription;
use App\Models\NewsArticle;
use App\Models\TrainingVideo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** v2: news articles and the training catalogue. */
class NewsAndTrainingTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = $this->user('member');
    }

    // ── News ────────────────────────────────────────────────────────────

    public function test_members_see_only_published_news_newest_first(): void
    {
        $old = $this->article(['title' => 'Older', 'published_at' => now()->subDays(3)]);
        $this->article(['title' => 'Newer', 'category' => 'offers', 'published_at' => now()->subDay()]);
        $this->article(['title' => 'Draft', 'is_published' => false]);
        $this->article(['title' => 'Scheduled', 'published_at' => now()->addDay()]);

        $this->as($this->member)->getJson('/api/v1/mobile/news')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Newer')
            ->assertJsonMissingPath('data.0.body');

        $this->as($this->member)->getJson('/api/v1/mobile/news?category=offers')->assertJsonCount(1, 'data');
        $this->as($this->member)->getJson("/api/v1/mobile/news/{$old->id}")->assertJsonPath('data.body.0', 'First paragraph.');

        $draft = NewsArticle::where('title', 'Draft')->first();
        $this->as($this->member)->getJson("/api/v1/mobile/news/{$draft->id}")->assertNotFound();
    }

    public function test_only_full_admins_manage_news(): void
    {
        Storage::fake('public');
        $payload = ['category' => 'wellness_tips', 'title' => 'Sleep well', 'excerpt' => 'Why rest matters', 'body' => ['Rest.'], 'is_published' => true];

        $this->as($this->member)->postJson('/api/v1/content/news', $payload)->assertForbidden();
        $this->as($this->user('company_admin'))->postJson('/api/v1/content/news', $payload)->assertForbidden();

        $id = $this->as($this->user('fitaccess_admin'))
            ->post('/api/v1/content/news', $payload + ['image' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');

        $n = NewsArticle::find($id);
        $this->assertNotNull($n->published_at, 'publishing stamps the date');
        Storage::disk('public')->assertExists($n->image_path);
        $this->as($this->member)->getJson('/api/v1/mobile/news')->assertJsonPath('data.0.title', 'Sleep well');
    }

    // ── Training ────────────────────────────────────────────────────────

    public function test_premium_videos_are_locked_without_a_premium_plan(): void
    {
        $premium = $this->video(['title' => 'HIIT 20', 'is_featured' => true]);
        $free = $this->video(['title' => 'Stretch 5', 'is_premium' => false, 'category' => 'Mobility']);

        $this->as($this->member)->getJson('/api/v1/mobile/training')
            ->assertOk()
            ->assertJsonPath('data.unlocked', false)
            ->assertJsonPath('data.featured.title', 'HIIT 20')
            ->assertJsonPath('data.featured.locked', true)
            ->assertJsonPath('data.rows.0.videos.0.locked', false)
            ->assertJsonMissingPath('data.featured.video_url');

        $this->as($this->member)->getJson("/api/v1/mobile/training/{$premium->id}")->assertJsonPath('data.video_url', null);
        $this->as($this->member)->getJson("/api/v1/mobile/training/{$free->id}")->assertJsonPath('data.video_url', 'https://video.test/v');
    }

    public function test_premium_plans_can_play_and_track_progress(): void
    {
        $plan = MembershipPlan::forceCreate(['name' => 'Fit Premium', 'tier' => 'premium', 'monthly_fee_etb' => 1000]);
        // Ends today: still active for the whole day.
        MobileSubscription::forceCreate(['user_id' => $this->member->id, 'plan_id' => $plan->id, 'status' => 'active', 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->toDateString()]);
        $v = $this->video();

        $this->as($this->member)->getJson("/api/v1/mobile/training/{$v->id}")->assertJsonPath('data.video_url', 'https://video.test/v');

        $this->as($this->member)->postJson("/api/v1/mobile/training/{$v->id}/progress", ['progress' => 0.4])->assertOk();
        $this->as($this->member)->postJson("/api/v1/mobile/training/{$v->id}/progress", ['saved' => true])->assertOk();
        $this->as($this->member)->postJson("/api/v1/mobile/training/{$v->id}/progress", ['progress' => 1.5])->assertUnprocessable();

        $this->as($this->member)->getJson('/api/v1/mobile/training')
            ->assertJsonPath('data.unlocked', true)
            ->assertJsonPath('data.continue_watching.0.id', $v->id)
            ->assertJsonPath('data.continue_watching.0.progress', 0.4)
            ->assertJsonPath('data.continue_watching.0.saved', true);
    }

    public function test_unpublished_videos_are_hidden_and_admins_need_https_links(): void
    {
        $hidden = $this->video(['is_published' => false]);
        $this->as($this->member)->getJson("/api/v1/mobile/training/{$hidden->id}")->assertNotFound();
        $this->as($this->member)->postJson("/api/v1/mobile/training/{$hidden->id}/progress", ['progress' => 0.1])->assertNotFound();

        $admin = $this->user('fitaccess_admin');
        $payload = ['title' => 'Core', 'category' => 'Strength', 'level' => 'Beginner', 'coach' => 'Hana', 'minutes' => 15, 'description' => 'Core basics', 'is_featured' => true, 'is_published' => true];
        $this->as($admin)->postJson('/api/v1/content/training', $payload + ['video_url' => 'http://video.test/x'])->assertUnprocessable();

        $old = $this->video(['is_featured' => true]);
        $this->as($admin)->postJson('/api/v1/content/training', $payload + ['video_url' => 'https://video.test/x'])->assertCreated();
        $this->assertFalse((bool) $old->fresh()->is_featured, 'only one featured video');
    }

    private function article(array $a = []): NewsArticle
    {
        return NewsArticle::forceCreate($a + [
            'category' => 'partner_news', 'title' => 'Title', 'excerpt' => 'Excerpt',
            'body' => ['First paragraph.'], 'is_published' => true, 'published_at' => now()->subHour(),
        ]);
    }

    private function video(array $a = []): TrainingVideo
    {
        return TrainingVideo::forceCreate($a + [
            'title' => 'Video', 'category' => 'Strength', 'level' => 'Beginner', 'coach' => 'Hana',
            'minutes' => 20, 'description' => 'd', 'video_url' => 'https://video.test/v',
            'is_premium' => true, 'is_published' => true,
        ]);
    }

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);

        return $this;
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
