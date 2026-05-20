<?php

namespace App\Http\Controllers;

use App\Models\Gym;
use App\Models\PartnerApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartnerApplicationController extends Controller
{
    /* ── List all applications ─────────────────────────────── */

    public function index(Request $request): JsonResponse
    {
        $applications = PartnerApplication::with('user:id,name,email,is_active')
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->get()
            ->map(fn($a) => [
                'id'                     => $a->id,
                'facility_name'          => $a->facility_name,
                'categories'             => $a->categories,
                'contact_person'         => $a->contact_person,
                'contact_phone'          => $a->contact_phone,
                'contact_email'          => $a->contact_email,
                'tin_number'             => $a->tin_number,
                'business_license_url'   => $a->business_license_path
                    ? asset('storage/' . $a->business_license_path)
                    : null,
                'city'                   => $a->city,
                'sub_city'               => $a->sub_city,
                'woreda'                 => $a->woreda,
                'landmark'               => $a->landmark,
                'google_maps_link'       => $a->google_maps_link,
                'operating_hours_summary'=> $a->operating_hours_summary,
                'weekday_open'           => $a->weekday_open,
                'weekday_close'          => $a->weekday_close,
                'weekend_open'           => $a->weekend_open,
                'weekend_close'          => $a->weekend_close,
                'max_capacity'           => $a->max_capacity,
                'amenities'              => $a->amenities ?? [],
                'status'                 => $a->status,
                'rejection_reason'       => $a->rejection_reason,
                'submitted_at'           => $a->created_at->toDateString(),
                'user'                   => $a->user ? [
                    'id'        => $a->user->id,
                    'email'     => $a->user->email,
                    'is_active' => $a->user->is_active,
                ] : null,
            ]);

        $pendingCount = PartnerApplication::where('status', 'pending')->count();

        return response()->json([
            'data'          => $applications,
            'total'         => $applications->count(),
            'pending_count' => $pendingCount,
        ]);
    }

    /* ── Approve → create Gym ──────────────────────────────── */

    public function approve(Request $request, PartnerApplication $partnerApplication): JsonResponse
    {
        if ($partnerApplication->status !== 'pending') {
            return response()->json(['message' => 'Application has already been processed.'], 422);
        }

        $request->validate([
            'tier' => 'required|in:basic,basic_plus,premium,platinum',
        ]);

        return DB::transaction(function () use ($request, $partnerApplication): JsonResponse {

            // Build address from woreda + landmark
            $address = $partnerApplication->woreda;
            if ($partnerApplication->landmark) {
                $address .= ', ' . $partnerApplication->landmark;
            }

            // Build opening_hours JSON
            $openingHours = [
                'weekdays' => $partnerApplication->weekday_open && $partnerApplication->weekday_close
                    ? $partnerApplication->weekday_open . '–' . $partnerApplication->weekday_close
                    : null,
                'weekends' => $partnerApplication->weekend_open && $partnerApplication->weekend_close
                    ? $partnerApplication->weekend_open . '–' . $partnerApplication->weekend_close
                    : 'Closed',
                'summary'  => $partnerApplication->operating_hours_summary,
            ];

            // Create the Gym record
            $gym = Gym::create([
                'name'            => $partnerApplication->facility_name,
                'contact_person'  => $partnerApplication->contact_person,
                'contact_phone'   => $partnerApplication->contact_phone,
                'contact_email'   => $partnerApplication->contact_email,
                'address'         => $address,
                'sub_city'        => $partnerApplication->sub_city,
                'city'            => $partnerApplication->city,
                'tier'            => $request->tier,
                'max_capacity'    => $partnerApplication->max_capacity,
                'facilities'      => array_merge(
                    $partnerApplication->categories ?? [],
                    $partnerApplication->amenities  ?? []
                ),
                'opening_hours'   => $openingHours,
                'is_active'       => true,
                'is_partner'      => true,
                'partnership_start' => now()->toDateString(),
            ]);

            // Update application status
            $partnerApplication->update(['status' => 'approved']);

            // Activate the partner user account
            $partnerApplication->user?->update(['is_active' => true]);

            return response()->json([
                'message' => "Partner approved. Gym \"{$gym->name}\" is now live.",
                'gym_id'  => $gym->id,
            ]);
        });
    }

    /* ── Reject ────────────────────────────────────────────── */

    public function reject(Request $request, PartnerApplication $partnerApplication): JsonResponse
    {
        if ($partnerApplication->status !== 'pending') {
            return response()->json(['message' => 'Application has already been processed.'], 422);
        }

        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $partnerApplication->update([
            'status'           => 'rejected',
            'rejection_reason' => $request->reason,
        ]);

        $partnerApplication->user?->update(['is_active' => false]);

        return response()->json(['message' => 'Application rejected.']);
    }
}
