<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\NewsArticle;
use App\Models\TrainingVideo;
use App\Support\PlanAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * v2 content: news and training videos. Members read; full admins
 * manage. Training playback URLs are returned only to members whose plan
 * includes premium training (everyone else sees the catalogue locked).
 */
class ContentController extends Controller
{
    // ── Member: news ─────────────────────────────────────────────────────

    /** GET /mobile/news?category= */
    public function news(Request $request): JsonResponse
    {
        $category = $request->query('category');
        $page = NewsArticle::visible()
            ->when(in_array($category, NewsArticle::CATEGORIES, true), fn ($q) => $q->where('category', $category))
            ->orderByDesc('published_at')
            ->paginate(min((int) $request->query('per_page', 20), 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (NewsArticle $n) => $this->newsJson($n, false)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** GET /mobile/news/{article} */
    public function newsArticle(int $id): JsonResponse
    {
        $n = NewsArticle::visible()->find($id);

        return $n ? response()->json(['data' => $this->newsJson($n, true)]) : response()->json(['message' => 'Not found.'], 404);
    }

    // ── Member: training ─────────────────────────────────────────────────

    /** GET /mobile/training */
    public function training(Request $request): JsonResponse
    {
        $user = $request->user();
        $unlocked = PlanAccess::hasPremiumTraining($user);
        $videos = TrainingVideo::where('is_published', true)->orderBy('sort')->orderBy('id')->get();
        $progress = DB::table('training_progress')->where('user_id', $user->id)->get()->keyBy('training_video_id');

        $json = fn (TrainingVideo $v) => $this->videoJson($v, $unlocked, $progress[$v->id] ?? null, false);
        $featured = $videos->firstWhere('is_featured', true);

        return response()->json(['data' => [
            'unlocked' => $unlocked,
            'featured' => $featured ? $json($featured) : null,
            'continue_watching' => $unlocked
                ? $videos->filter(fn ($v) => ($p = $progress[$v->id] ?? null) && $p->progress > 0 && $p->progress < 1)->map($json)->values()
                : [],
            'rows' => $videos->reject(fn ($v) => $featured && $v->id === $featured->id)
                ->groupBy('category')
                ->map(fn ($group, $title) => ['title' => $title, 'videos' => $group->map($json)->values()])
                ->values(),
            'categories' => $videos->pluck('category')->unique()->values(),
        ]]);
    }

    /** GET /mobile/training/{video} */
    public function trainingVideo(Request $request, int $id): JsonResponse
    {
        $v = TrainingVideo::where('is_published', true)->find($id);
        if (!$v) {
            return response()->json(['message' => 'Not found.'], 404);
        }
        $user = $request->user();
        $p = DB::table('training_progress')->where('user_id', $user->id)->where('training_video_id', $v->id)->first();

        return response()->json(['data' => $this->videoJson($v, PlanAccess::hasPremiumTraining($user), $p, true)]);
    }

    /** POST /mobile/training/{video}/progress {progress?: 0–1, saved?: bool} */
    public function trainingProgress(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['progress' => 'sometimes|numeric|between:0,1', 'saved' => 'sometimes|boolean']);
        $v = TrainingVideo::where('is_published', true)->find($id);
        if (!$v) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        DB::table('training_progress')->updateOrInsert(
            ['user_id' => $request->user()->id, 'training_video_id' => $v->id],
            array_filter(['progress' => $data['progress'] ?? null, 'saved' => isset($data['saved']) ? (int) $data['saved'] : null], fn ($x) => $x !== null) + ['updated_at' => now(), 'created_at' => now()],
        );

        return response()->json(['message' => 'Saved.']);
    }

    // ── Admin ────────────────────────────────────────────────────────────

    public function adminNews(): JsonResponse
    {
        return response()->json(['data' => NewsArticle::orderByDesc('id')->paginate(30)]);
    }

    public function saveNews(Request $request, ?int $id = null): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(NewsArticle::CATEGORIES)],
            'title' => 'required|string|max:160',
            'excerpt' => 'required|string|max:300',
            'body' => 'required|array|min:1',
            'body.*' => 'string|max:4000',
            'read_minutes' => 'nullable|integer|between:1,60',
            'is_published' => 'boolean',
            'published_at' => 'nullable|date',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:4096',
        ]);
        $n = $id ? NewsArticle::findOrFail($id) : new NewsArticle(['created_by' => $request->user()->id]);
        if ($request->hasFile('image')) {
            if ($n->image_path) {
                Storage::disk('public')->delete($n->image_path);
            }
            $data['image_path'] = $request->file('image')->store('news', 'public');
        }
        unset($data['image']);
        $data['published_at'] = $data['published_at'] ?? (($data['is_published'] ?? false) ? ($n->published_at ?? now()) : null);
        $n->fill($data)->save();
        AuditLog::record($id ? 'news_updated' : 'news_created', $n);

        return response()->json(['data' => $this->newsJson($n, true)], $id ? 200 : 201);
    }

    public function deleteNews(int $id): JsonResponse
    {
        $n = NewsArticle::findOrFail($id);
        if ($n->image_path) {
            Storage::disk('public')->delete($n->image_path);
        }
        $n->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    public function adminTraining(): JsonResponse
    {
        return response()->json(['data' => TrainingVideo::orderBy('sort')->orderBy('id')->get()->makeVisible('video_url')]);
    }

    public function saveTraining(Request $request, ?int $id = null): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:160',
            'category' => 'required|string|max:60',
            'level' => 'required|string|max:40',
            'coach' => 'required|string|max:80',
            'minutes' => 'required|integer|between:1,240',
            'description' => 'required|string|max:4000',
            'equipment' => 'nullable|array',
            'equipment.*' => 'string|max:60',
            'what_you_need' => 'nullable|array',
            'what_you_need.*' => 'string|max:120',
            // Only HTTPS links to the video service.
            'video_url' => ['nullable', 'url:https', 'max:500'],
            'is_premium' => 'boolean',
            'is_featured' => 'boolean',
            'is_published' => 'boolean',
            'sort' => 'nullable|integer|between:0,9999',
            'thumbnail' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:4096',
        ]);
        $v = $id ? TrainingVideo::findOrFail($id) : new TrainingVideo();
        if ($request->hasFile('thumbnail')) {
            if ($v->thumbnail_path) {
                Storage::disk('public')->delete($v->thumbnail_path);
            }
            $data['thumbnail_path'] = $request->file('thumbnail')->store('training', 'public');
        }
        unset($data['thumbnail']);
        DB::transaction(function () use ($v, $data) {
            if (!empty($data['is_featured'])) {
                TrainingVideo::where('is_featured', true)->update(['is_featured' => false]);
            }
            $v->fill($data)->save();
        });
        AuditLog::record($id ? 'training_updated' : 'training_created', $v);

        return response()->json(['data' => $v->makeVisible('video_url')], $id ? 200 : 201);
    }

    public function deleteTraining(int $id): JsonResponse
    {
        $v = TrainingVideo::findOrFail($id);
        if ($v->thumbnail_path) {
            Storage::disk('public')->delete($v->thumbnail_path);
        }
        $v->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    // ── JSON ─────────────────────────────────────────────────────────────

    private function fileUrl(?string $path): ?string
    {
        return $path ? config('fitaccess.api_url').'/api/v1/files/'.ltrim($path, '/') : null;
    }

    private function newsJson(NewsArticle $n, bool $withBody): array
    {
        return array_filter([
            'id' => $n->id,
            'category' => $n->category,
            'title' => $n->title,
            'excerpt' => $n->excerpt,
            'image_url' => $this->fileUrl($n->image_path),
            'read_minutes' => $n->read_minutes,
            'published_at' => $n->published_at?->toIso8601String(),
            'body' => $withBody ? $n->body : null,
        ], fn ($v) => $v !== null);
    }

    private function videoJson(TrainingVideo $v, bool $unlocked, ?object $p, bool $detail): array
    {
        $canPlay = $unlocked || !$v->is_premium;

        return [
            'id' => $v->id,
            'title' => $v->title,
            'category' => $v->category,
            'level' => $v->level,
            'coach' => $v->coach,
            'minutes' => $v->minutes,
            'thumbnail_url' => $this->fileUrl($v->thumbnail_path),
            'is_premium' => $v->is_premium,
            'is_featured' => $v->is_featured,
            'locked' => !$canPlay,
            'progress' => $p ? (float) $p->progress : 0.0,
            'saved' => $p ? (bool) $p->saved : false,
        ] + ($detail ? [
            'description' => $v->description,
            'equipment' => $v->equipment ?? [],
            'what_you_need' => $v->what_you_need ?? [],
            // Playback only for plans that include it.
            'video_url' => $canPlay ? $v->video_url : null,
        ] : []);
    }
}
