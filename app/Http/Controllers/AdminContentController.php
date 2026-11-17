<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\NewsPost;
use App\Models\WellnessProgram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * FitAccess admin: News and Trainings shown on the employee portal.
 * Requests are multipart/form-data so a cover image can be uploaded.
 */
class AdminContentController extends Controller
{
    private const IMAGE_RULE = 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096';

    public const TRAINING_CATEGORIES = ['fitness', 'mental_health', 'nutrition', 'medical', 'yoga', 'lifestyle', 'other'];

    /** Companies a news post can be targeted at (id + name only). */
    public function companies(): JsonResponse
    {
        return response()->json(
            \App\Models\Company::orderBy('name')->get(['id', 'name'])
        );
    }

    // ── News ────────────────────────────────────────────────────────

    private function newsRow(NewsPost $n): array
    {
        return [
            'id'           => $n->id,
            'title'        => $n->title,
            'category'     => $n->category,
            'excerpt'      => $n->excerpt,
            'body'         => $n->body,
            'image_url'    => $n->imageUrl(),
            'link_url'     => $n->link_url,
            'link_label'   => $n->link_label,
            'company_id'   => $n->company_id,
            'company_name' => $n->company?->name,
            'is_featured'  => $n->is_featured,
            'is_published' => $n->is_published,
            'published_at' => $n->published_at?->toDateString(),
            'created_at'   => $n->created_at?->toDateString(),
        ];
    }

    public function newsIndex(Request $request): JsonResponse
    {
        $items = NewsPost::with('company:id,name')
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%' . $request->search . '%'))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => collect($items->items())->map(fn ($n) => $this->newsRow($n))->values(),
            'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total()],
        ]);
    }

    private function newsRules(bool $update): array
    {
        $req = $update ? 'sometimes|required' : 'required';

        return [
            'title'        => "$req|string|max:200",
            'category'     => "$req|string|max:50",
            'excerpt'      => 'nullable|string|max:300',
            'body'         => 'nullable|string|max:20000',
            'image'        => self::IMAGE_RULE,
            'remove_image' => 'nullable|boolean',
            'link_url'     => 'nullable|url|max:500',
            'link_label'   => 'nullable|string|max:60',
            'company_id'   => 'nullable|integer|exists:companies,id',
            'is_featured'  => 'nullable|boolean',
            'is_published' => 'nullable|boolean',
            'published_at' => 'nullable|date',
        ];
    }

    public function newsStore(Request $request): JsonResponse
    {
        $data = $request->validate($this->newsRules(false));
        $data['image_path'] = $request->hasFile('image') ? $request->file('image')->store('news', 'public') : null;
        $data['created_by'] = $request->user()->id;
        $data['published_at'] ??= now();
        unset($data['image'], $data['remove_image']);

        $post = NewsPost::create($data);
        AuditLog::record('created', $post);

        return response()->json(['message' => 'News published.', 'news' => $this->newsRow($post->load('company:id,name'))], 201);
    }

    public function newsUpdate(Request $request, NewsPost $newsPost): JsonResponse
    {
        $data = $request->validate($this->newsRules(true));
        $data = $this->applyImage($request, $data, $newsPost->image_path, 'news');
        unset($data['image'], $data['remove_image']);

        $newsPost->update($data);
        AuditLog::record('updated', $newsPost);

        return response()->json(['message' => 'News updated.', 'news' => $this->newsRow($newsPost->fresh('company'))]);
    }

    public function newsDestroy(NewsPost $newsPost): JsonResponse
    {
        $newsPost->delete();
        AuditLog::record('deleted', $newsPost);

        return response()->json(['message' => 'News deleted.']);
    }

    // ── Trainings (wellness programs) ───────────────────────────────

    private function trainingRow(WellnessProgram $t): array
    {
        return [
            'id'               => $t->id,
            'title'            => $t->title,
            'description'      => $t->description,
            'category'         => $t->category,
            'image_url'        => $t->imageUrl(),
            'video_url'        => $t->video_url,
            'duration_minutes' => $t->duration_minutes,
            'provider'         => $t->provider,
            'provider_contact' => $t->provider_contact,
            'location'         => $t->location,
            'is_online'        => $t->is_online,
            'start_date'       => $t->start_date?->toDateString(),
            'end_date'         => $t->end_date?->toDateString(),
            'max_participants' => $t->max_participants,
            'is_active'        => $t->is_active,
            'participants'     => $t->participants_count ?? null,
        ];
    }

    public function trainingsIndex(Request $request): JsonResponse
    {
        $items = WellnessProgram::query()
            ->withCount(['employees as participants_count' => fn ($q) => $q->where('employee_wellness.status', 'enrolled')])
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%' . $request->search . '%'))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data'       => collect($items->items())->map(fn ($t) => $this->trainingRow($t))->values(),
            'categories' => self::TRAINING_CATEGORIES,
            'meta'       => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total()],
        ]);
    }

    private function trainingRules(bool $update): array
    {
        $req = $update ? 'sometimes|required' : 'required';

        return [
            'title'            => "$req|string|max:255",
            'category'         => "$req|in:" . implode(',', self::TRAINING_CATEGORIES),
            'description'      => 'nullable|string|max:20000',
            'image'            => self::IMAGE_RULE,
            'remove_image'     => 'nullable|boolean',
            'video_url'        => 'nullable|url|max:500',
            'duration_minutes' => 'nullable|integer|min:1|max:1440',
            'provider'         => 'nullable|string|max:255',
            'provider_contact' => 'nullable|string|max:255',
            'location'         => 'nullable|string|max:255',
            'is_online'        => 'nullable|boolean',
            'start_date'       => 'nullable|date',
            'end_date'         => 'nullable|date|after_or_equal:start_date',
            'max_participants' => 'nullable|integer|min:1',
            'is_active'        => 'nullable|boolean',
        ];
    }

    public function trainingStore(Request $request): JsonResponse
    {
        $data = $request->validate($this->trainingRules(false));
        $data['image_path'] = $request->hasFile('image') ? $request->file('image')->store('trainings', 'public') : null;
        $data['is_active'] ??= true;
        $data['is_online'] ??= false;
        unset($data['image'], $data['remove_image']);

        $training = WellnessProgram::create($data);
        AuditLog::record('created', $training);

        return response()->json(['message' => 'Training created.', 'training' => $this->trainingRow($training)], 201);
    }

    public function trainingUpdate(Request $request, WellnessProgram $wellnessProgram): JsonResponse
    {
        $data = $request->validate($this->trainingRules(true));
        $data = $this->applyImage($request, $data, $wellnessProgram->image_path, 'trainings');
        unset($data['image'], $data['remove_image']);

        $wellnessProgram->update($data);
        AuditLog::record('updated', $wellnessProgram);

        return response()->json(['message' => 'Training updated.', 'training' => $this->trainingRow($wellnessProgram->fresh())]);
    }

    public function trainingDestroy(WellnessProgram $wellnessProgram): JsonResponse
    {
        $wellnessProgram->delete();
        AuditLog::record('deleted', $wellnessProgram);

        return response()->json(['message' => 'Training deleted.']);
    }

    /** New upload replaces the old image; remove_image clears it. */
    private function applyImage(Request $request, array $data, ?string $current, string $dir): array
    {
        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store($dir, 'public');
        } elseif ($request->boolean('remove_image')) {
            $data['image_path'] = null;
        } else {
            return $data;
        }
        if ($current) {
            Storage::disk('public')->delete($current);
        }

        return $data;
    }
}
