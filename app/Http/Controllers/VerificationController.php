<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MemberVerification;
use App\Services\VerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * v2 identity verification: members submit a selfie; admins can review
 * the queue (gym staff confirm at the first visit in the partner app).
 */
class VerificationController extends Controller
{
    public function __construct(private readonly VerificationService $verifications) {}

    // ── Member ───────────────────────────────────────────────────────────

    /** POST /mobile/verification (multipart "photo") */
    public function submit(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpeg,jpg,png,webp|max:6144|dimensions:min_width=320,min_height=320',
        ]);

        $v = $this->verifications->submit($request->user(), $request->file('photo'));

        return response()->json([
            'data' => [
                'status' => $this->verifications->statusFor($request->user()),
                'submitted_at' => $v->created_at?->toIso8601String(),
            ],
            'message' => 'Photo submitted. The front desk will compare it with your ID on your first visit.',
        ], 201);
    }

    /** GET /mobile/verification */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $latest = $this->verifications->latest($user);

        return response()->json(['data' => [
            'status' => $this->verifications->statusFor($user),
            'rejection_reason' => $latest?->status === MemberVerification::REJECTED ? $latest->rejection_reason : null,
            'has_photo' => $latest !== null,
        ]]);
    }

    /** GET /mobile/verification/photo — the member's own photo. */
    public function myPhoto(Request $request): StreamedResponse|JsonResponse
    {
        $v = $this->verifications->photoFor($request->user());

        return $v ? $this->stream($v) : response()->json(['message' => 'No photo yet.'], 404);
    }

    // ── Admin ────────────────────────────────────────────────────────────

    /** GET /verifications?status=pending */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', MemberVerification::PENDING);
        $page = MemberVerification::with('user:id,name,email,phone')
            ->when(in_array($status, ['pending', 'verified', 'rejected'], true), fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (MemberVerification $v) => [
                'id' => $v->id,
                'status' => $v->status,
                'rejection_reason' => $v->rejection_reason,
                'submitted_at' => $v->created_at?->toIso8601String(),
                'reviewed_at' => $v->reviewed_at?->toIso8601String(),
                'member' => $v->user ? ['id' => $v->user->id, 'name' => $v->user->name, 'email' => $v->user->email, 'phone' => $v->user->phone] : null,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** GET /verifications/{verification}/photo */
    public function photo(MemberVerification $verification): StreamedResponse|JsonResponse
    {
        return $this->stream($verification);
    }

    /** POST /verifications/{verification}/approve */
    public function approve(Request $request, MemberVerification $verification): JsonResponse
    {
        $this->verifications->approve($verification, $request->user());
        AuditLog::record('verification_approved', $verification);

        return response()->json(['data' => ['status' => $verification->status]]);
    }

    /** POST /verifications/{verification}/reject */
    public function reject(Request $request, MemberVerification $verification): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $this->verifications->reject($verification, $request->user(), $data['reason']);
        AuditLog::record('verification_rejected', $verification);

        return response()->json(['data' => ['status' => $verification->status]]);
    }

    private function stream(MemberVerification $v): StreamedResponse|JsonResponse
    {
        $disk = Storage::disk(VerificationService::DISK);
        if (!$disk->exists($v->photo_path)) {
            return response()->json(['message' => 'Photo not found.'], 404);
        }

        return $disk->response($v->photo_path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
