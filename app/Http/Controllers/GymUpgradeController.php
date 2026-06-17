<?php

namespace App\Http\Controllers;

use App\Models\AdminNotification;
use App\Models\AuditLog;
use App\Models\Gym;
use App\Models\GymUpgradeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GymUpgradeController extends Controller
{
    /* ── Shared helper ──────────────────────────────────────────── */

    private function normalizeTier(string $tier): string
    {
        $norm = strtolower(
            preg_replace('/^[a-z]+_(?=basic|premium|platinum|gold|silver)/i', '', $tier) ?? $tier
        );
        return match (true) {
            str_contains($norm, 'platinum') || str_contains($norm, 'gold') => 'platinum',
            str_contains($norm, 'premium')                                 => 'premium',
            str_contains($norm, 'basic_plus') || str_contains($norm, 'plus') => 'basic_plus',
            default                                                        => 'basic',
        };
    }

    private function getMyGym(): Gym
    {
        $user = auth()->user();
        $gym  = $user->gym_id
            ? Gym::find($user->gym_id)
            : Gym::where('contact_email', $user->email)->first();
        if (!$gym) abort(403, 'No gym is linked to this partner account.');
        return $gym;
    }

    /* ── Partner: update own profile ───────────────────────────── */

    public function updateProfile(Request $request): JsonResponse
    {
        $gym = $this->getMyGym();

        $data = $request->validate([
            'name'           => 'sometimes|string|max:100',
            'contact_person' => 'sometimes|string|max:100',
            'contact_phone'  => 'sometimes|string|max:30',
            'address'        => 'sometimes|string|max:255',
            'sub_city'       => 'sometimes|string|max:100',
            'city'           => 'sometimes|string|max:100',
            'max_capacity'   => 'sometimes|integer|min:1',
            'facilities'     => 'sometimes|array',
            'facilities.*'   => 'string|max:100',
            'opening_hours'  => 'sometimes|array',
        ]);

        $old = $gym->only(array_keys($data));
        $gym->update($data);
        AuditLog::record('updated', $gym, $old, $data);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'gym'     => $this->gymPayload($gym->fresh()),
        ]);
    }

    /* ── Partner: submit a tier upgrade request ─────────────────── */

    public function submitRequest(Request $request): JsonResponse
    {
        $gym = $this->getMyGym();

        $request->validate([
            'requested_tier' => 'required|string|max:50',
            'message'        => 'nullable|string|max:1000',
        ]);

        $requestedTier = $this->normalizeTier($request->requested_tier);

        // Prevent duplicate pending requests
        $existing = GymUpgradeRequest::where('gym_id', $gym->id)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'You already have a pending upgrade request. Please wait for admin review.',
            ], 422);
        }

        // Must request a higher tier
        $tierRank = ['basic' => 0, 'basic_plus' => 1, 'premium' => 2, 'platinum' => 3];
        $currentRank  = $tierRank[$this->normalizeTier($gym->tier)] ?? 0;
        $requestedRank = $tierRank[$requestedTier] ?? 0;

        if ($requestedRank <= $currentRank) {
            return response()->json([
                'message' => 'You can only request an upgrade to a higher tier than your current tier.',
            ], 422);
        }

        return DB::transaction(function () use ($request, $gym, $requestedTier): JsonResponse {
            $upgradeReq = GymUpgradeRequest::create([
                'gym_id'         => $gym->id,
                'requested_tier' => $requestedTier,
                'message'        => $request->message,
                'status'         => 'pending',
            ]);

            AdminNotification::gymUpgradeRequest(
                $gym->name,
                $gym->tier,
                $requestedTier,
                $gym->id,
                $upgradeReq->id
            );

            return response()->json([
                'message' => 'Upgrade request submitted. The admin will review your request.',
                'request' => $this->requestPayload($upgradeReq),
            ], 201);
        });
    }

    /* ── Partner: list own upgrade requests ─────────────────────── */

    public function myRequests(): JsonResponse
    {
        $gym      = $this->getMyGym();
        $requests = GymUpgradeRequest::where('gym_id', $gym->id)
            ->latest()
            ->get()
            ->map(fn($r) => $this->requestPayload($r));

        return response()->json($requests);
    }

    /* ── Admin: list all pending/recent upgrade requests ────────── */

    public function adminIndex(Request $request): JsonResponse
    {
        $requests = GymUpgradeRequest::with(['gym:id,name,tier,city', 'reviewer:id,name'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->get()
            ->map(fn($r) => [
                'id'             => $r->id,
                'gym_id'         => $r->gym_id,
                'gym_name'       => $r->gym?->name,
                'gym_city'       => $r->gym?->city,
                'current_tier'   => $r->gym?->tier,
                'requested_tier' => $r->requested_tier,
                'message'        => $r->message,
                'status'         => $r->status,
                'rejection_reason' => $r->rejection_reason,
                'reviewed_by'    => $r->reviewer?->name,
                'reviewed_at'    => $r->reviewed_at?->toDateTimeString(),
                'created_at'     => $r->created_at->toDateTimeString(),
            ]);

        $pendingCount = GymUpgradeRequest::where('status', 'pending')->count();

        return response()->json(['data' => $requests, 'pending_count' => $pendingCount]);
    }

    /* ── Admin: approve — update gym tier ───────────────────────── */

    public function adminApprove(Request $request, GymUpgradeRequest $gymUpgradeRequest): JsonResponse
    {
        if ($gymUpgradeRequest->status !== 'pending') {
            return response()->json(['message' => 'This request has already been processed.'], 422);
        }

        $request->validate([
            'tier' => 'nullable|string|max:50',  // admin can override the requested tier
        ]);

        $newTier = $this->normalizeTier($request->tier ?? $gymUpgradeRequest->requested_tier);

        return DB::transaction(function () use ($request, $gymUpgradeRequest, $newTier): JsonResponse {
            $gym = $gymUpgradeRequest->gym;
            $oldTier = $gym->tier;

            $gym->update(['tier' => $newTier]);

            $gymUpgradeRequest->update([
                'status'      => 'approved',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            AuditLog::record('updated', $gym, ['tier' => $oldTier], ['tier' => $newTier]);

            return response()->json([
                'message' => "Upgrade approved. {$gym->name} is now on {$newTier} tier.",
            ]);
        });
    }

    /* ── Admin: reject — require a reason ───────────────────────── */

    public function adminReject(Request $request, GymUpgradeRequest $gymUpgradeRequest): JsonResponse
    {
        if ($gymUpgradeRequest->status !== 'pending') {
            return response()->json(['message' => 'This request has already been processed.'], 422);
        }

        $request->validate([
            'reason' => 'required|string|min:5|max:1000',
        ]);

        $gymUpgradeRequest->update([
            'status'           => 'rejected',
            'rejection_reason' => $request->reason,
            'reviewed_by'      => auth()->id(),
            'reviewed_at'      => now(),
        ]);

        return response()->json([
            'message' => 'Upgrade request rejected.',
        ]);
    }

    /* ── Private helpers ────────────────────────────────────────── */

    private function gymPayload(Gym $gym): array
    {
        return [
            'id'             => $gym->id,
            'name'           => $gym->name,
            'tier'           => $gym->tier,
            'city'           => $gym->city,
            'sub_city'       => $gym->sub_city,
            'address'        => $gym->address,
            'contact_person' => $gym->contact_person,
            'contact_phone'  => $gym->contact_phone,
            'contact_email'  => $gym->contact_email,
            'max_capacity'   => $gym->max_capacity,
            'facilities'     => $gym->facilities ?? [],
            'opening_hours'  => $gym->opening_hours,
            'is_active'      => $gym->is_active,
            'partnership_start' => $gym->partnership_start?->toDateString(),
        ];
    }

    private function requestPayload(GymUpgradeRequest $r): array
    {
        return [
            'id'               => $r->id,
            'requested_tier'   => $r->requested_tier,
            'message'          => $r->message,
            'status'           => $r->status,
            'rejection_reason' => $r->rejection_reason,
            'reviewed_at'      => $r->reviewed_at?->toDateTimeString(),
            'created_at'       => $r->created_at->toDateTimeString(),
        ];
    }
}
