<?php

namespace App\Http\Controllers;

use App\Models\Gym;
use App\Models\MembershipPlan;
use App\Models\MobileSubscription;
use App\Models\Notification;
use App\Models\QrToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MobileController extends Controller
{
    // ── Dashboard — single call for home screen ──────────────────────────────

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        // Active subscription
        $subscription = MobileSubscription::with('plan')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('end_date', '>=', now())
            ->latest()
            ->first();

        // Checkin stats
        $allCheckins = DB::table('checkins')
            ->where('employee_id', function ($q) use ($user) {
                $q->select('id')->from('employees')->where('user_id', $user->id)->limit(1);
            })
            ->orWhere('user_id', $user->id) // B2C checkins directly linked to user
            ->get();

        $now = now();
        $monthCheckins = $allCheckins->filter(
            fn($c) => $c->checked_in_at && \Carbon\Carbon::parse($c->checked_in_at)->isCurrentMonth()
        )->count();
        $weekCheckins = $allCheckins->filter(
            fn($c) => $c->checked_in_at && \Carbon\Carbon::parse($c->checked_in_at)->isCurrentWeek()
        )->count();

        $uniqueFacilities = $allCheckins->pluck('gym_id')->unique()->count();
        $lastVisit = $allCheckins->sortByDesc('checked_in_at')->first();

        // Recent checkins — last 3
        $recentCheckins = DB::table('checkins')
            ->join('gyms', 'checkins.gym_id', '=', 'gyms.id')
            ->where(function ($q) use ($user) {
                $q->where('checkins.user_id', $user->id)
                  ->orWhereIn('checkins.employee_id', function ($sub) use ($user) {
                      $sub->select('id')->from('employees')->where('user_id', $user->id);
                  });
            })
            ->select(
                'checkins.id',
                'checkins.gym_id',
                'gyms.name as gym_name',
                'gyms.category as gym_category',
                'gyms.photo_url as gym_photo_url',
                'checkins.checked_in_at',
                'checkins.checked_out_at'
            )
            ->orderByDesc('checkins.checked_in_at')
            ->limit(3)
            ->get()
            ->map(function ($c) {
                $duration = null;
                if ($c->checked_in_at && $c->checked_out_at) {
                    $duration = \Carbon\Carbon::parse($c->checked_in_at)
                        ->diffInMinutes(\Carbon\Carbon::parse($c->checked_out_at));
                }
                return [
                    'id'               => $c->id,
                    'gym_id'           => $c->gym_id,
                    'gym_name'         => $c->gym_name,
                    'gym_category'     => $c->gym_category ?? 'gym',
                    'gym_photo_url'    => $c->gym_photo_url,
                    'checked_in_at'    => $c->checked_in_at,
                    'checked_out_at'   => $c->checked_out_at,
                    'duration_minutes' => $duration,
                ];
            });

        // Featured gyms — top 6 by quality score
        $featuredGyms = Gym::where('is_active', true)
            ->orderByDesc('quality_score')
            ->limit(6)
            ->get(['id', 'name', 'category', 'tier', 'address', 'sub_city', 'photo_url', 'quality_score']);

        // Unread notifications
        $unreadCount = Notification::where('user_id', $user->id)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'data' => [
                'profile' => [
                    'id'          => $user->id,
                    'name'        => $user->name,
                    'member_code' => $user->member_code,
                    'photo_url'   => $user->photo_path,
                ],
                'subscription' => $subscription ? [
                    'id'            => $subscription->id,
                    'status'        => $subscription->status,
                    'package_name'  => $subscription->plan?->name,
                    'package_tier'  => $subscription->plan?->tier,
                    'start_date'    => $subscription->start_date?->toDateString(),
                    'end_date'      => $subscription->end_date?->toDateString(),
                    'days_remaining'=> $subscription->daysRemaining(),
                    'billing_cycle' => $subscription->billing_cycle,
                    'amount_paid'   => (float) $subscription->amount_paid,
                ] : null,
                'stats' => [
                    'total_checkins'      => $allCheckins->count(),
                    'this_month_checkins' => $monthCheckins,
                    'this_week_checkins'  => $weekCheckins,
                    'facilities_visited'  => $uniqueFacilities,
                    'last_visit_date'     => $lastVisit
                        ? \Carbon\Carbon::parse($lastVisit->checked_in_at)->toDateString()
                        : null,
                ],
                'recent_checkins'             => $recentCheckins->values(),
                'featured_gyms'               => $featuredGyms,
                'unread_notifications_count'  => $unreadCount,
            ],
        ]);
    }

    // ── QR Token ─────────────────────────────────────────────────────────────

    public function qrToken(Request $request): JsonResponse
    {
        $user = $request->user();

        // Check active subscription
        $subscription = MobileSubscription::with('plan')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('end_date', '>=', now())
            ->latest()
            ->first();

        // Also accept employee membership
        if (!$subscription) {
            $hasEmployeeMembership = DB::table('memberships')
                ->join('employees', 'memberships.employee_id', '=', 'employees.id')
                ->where('employees.user_id', $user->id)
                ->where('memberships.status', 'active')
                ->where('memberships.end_date', '>=', now()->toDateString())
                ->exists();

            if (!$hasEmployeeMembership) {
                return response()->json([
                    'message' => 'No active subscription. Purchase a plan to get your QR code.',
                ], 403);
            }
        }

        // Reuse today's token if valid
        $existing = QrToken::where('user_id', $user->id)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if ($existing) {
            return response()->json([
                'data' => [
                    'token'        => $existing->token,
                    'member_code'  => $user->member_code,
                    'member_name'  => $user->name,
                    'expires_at'   => $existing->expires_at->toIso8601String(),
                    'package_name' => $subscription?->plan?->name ?? 'Employee Plan',
                    'package_tier' => $subscription?->plan?->tier ?? 'basic',
                ],
            ]);
        }

        // Generate new token valid until end of day
        $token = QrToken::create([
            'user_id'    => $user->id,
            'token'      => Str::random(64),
            'expires_at' => now()->endOfDay(),
        ]);

        return response()->json([
            'data' => [
                'token'        => $token->token,
                'member_code'  => $user->member_code,
                'member_name'  => $user->name,
                'expires_at'   => $token->expires_at->toIso8601String(),
                'package_name' => $subscription?->plan?->name ?? 'Employee Plan',
                'package_tier' => $subscription?->plan?->tier ?? 'basic',
            ],
        ]);
    }

    // ── Profile ───────────────────────────────────────────────────────────────

    public function profile(Request $request): JsonResponse
    {
        $user = $request->user()->load('employee.company');

        return response()->json([
            'data' => [
                'id'           => $user->id,
                'name'         => $user->name,
                'email'        => $user->email,
                'phone'        => $user->phone,
                'member_code'  => $user->member_code,
                'fan_number'   => $user->fan_number,
                'photo_url'    => $user->photo_path,
                'job_title'    => $user->employee?->job_title,
                'department'   => $user->employee?->department,
                'company_name' => $user->employee?->company?->name,
                'created_at'   => $user->created_at?->toIso8601String(),
            ],
        ]);
    }

    // ── Update profile ────────────────────────────────────────────────────────

    public function updateProfile(Request $request): JsonResponse
    {
        $request->validate([
            'name'  => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
        ]);

        $user = $request->user();
        $user->update($request->only('name', 'phone'));

        return response()->json([
            'data'    => ['id' => $user->id, 'name' => $user->name, 'phone' => $user->phone],
            'message' => 'Profile updated successfully.',
        ]);
    }

    // ── Upload profile photo ──────────────────────────────────────────────────

    public function uploadPhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpg,jpeg,png|max:5120',
        ]);

        $user = $request->user();

        // Delete old photo
        if ($user->photo_path) {
            Storage::disk('public')->delete($user->photo_path);
        }

        $path = $request->file('photo')->store("profile-photos/{$user->id}", 'public');
        $user->update(['photo_path' => $path]);
        $url = Storage::disk('public')->url($path);

        return response()->json([
            'data'    => ['photo_url' => $url],
            'message' => 'Photo updated successfully.',
        ]);
    }

    // ── Checkin history ───────────────────────────────────────────────────────

    public function checkins(Request $request): JsonResponse
    {
        $user     = $request->user();
        $perPage  = min((int) ($request->per_page ?? 20), 50);

        $query = DB::table('checkins')
            ->join('gyms', 'checkins.gym_id', '=', 'gyms.id')
            ->where(function ($q) use ($user) {
                $q->where('checkins.user_id', $user->id)
                  ->orWhereIn('checkins.employee_id', function ($sub) use ($user) {
                      $sub->select('id')->from('employees')->where('user_id', $user->id);
                  });
            })
            ->when($request->month, function ($q) use ($request) {
                // e.g. ?month=2026-01
                [$year, $month] = explode('-', $request->month);
                $q->whereYear('checkins.checked_in_at', $year)
                  ->whereMonth('checkins.checked_in_at', $month);
            })
            ->select(
                'checkins.id',
                'checkins.gym_id',
                'gyms.name as gym_name',
                'gyms.category as gym_category',
                'gyms.photo_url as gym_photo_url',
                'checkins.checked_in_at',
                'checkins.checked_out_at'
            )
            ->orderByDesc('checkins.checked_in_at');

        $paginated = $query->paginate($perPage);

        $items = collect($paginated->items())->map(function ($c) {
            $duration = null;
            if ($c->checked_in_at && $c->checked_out_at) {
                $duration = \Carbon\Carbon::parse($c->checked_in_at)
                    ->diffInMinutes(\Carbon\Carbon::parse($c->checked_out_at));
            }
            return [
                'id'               => $c->id,
                'gym_id'           => $c->gym_id,
                'gym_name'         => $c->gym_name,
                'gym_category'     => $c->gym_category ?? 'gym',
                'gym_photo_url'    => $c->gym_photo_url,
                'checked_in_at'    => $c->checked_in_at,
                'checked_out_at'   => $c->checked_out_at,
                'duration_minutes' => $duration,
            ];
        });

        return response()->json([
            'data' => $items->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
            ],
        ]);
    }

    // ── Packages ──────────────────────────────────────────────────────────────

    public function packages(): JsonResponse
    {
        $plans = MembershipPlan::where('is_active', true)
            ->orderBy('monthly_fee_etb')
            ->get();

        $data = $plans->map(function ($plan) {
            // Count gyms accessible per tier
            $tiers = $this->accessibleTiers($plan->tier);
            $counts = Gym::where('is_active', true)
                ->whereIn('tier', $tiers)
                ->selectRaw('category, count(*) as total')
                ->groupBy('category')
                ->pluck('total', 'category');

            return [
                'id'                             => $plan->id,
                'name'                           => $plan->name,
                'tier'                           => $plan->tier,
                'description'                    => $plan->features[0] ?? null,
                'monthly_price'                  => (float) $plan->monthly_fee_etb,
                'annual_price'                   => round($plan->monthly_fee_etb * 12 * 0.8, 2),
                'features'                       => $plan->features ?? [],
                'max_checkins_per_gym_per_month' => null, // unlimited for now
                'facilities_count'               => $counts,
                'is_active'                      => $plan->is_active,
            ];
        });

        return response()->json(['data' => $data]);
    }

    // ── Subscription ──────────────────────────────────────────────────────────

    public function subscription(Request $request): JsonResponse
    {
        $sub = MobileSubscription::with('plan')
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->where('end_date', '>=', now())
            ->latest()
            ->first();

        if (!$sub) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => [
                'id'             => $sub->id,
                'status'         => $sub->status,
                'package_id'     => $sub->plan_id,
                'package_name'   => $sub->plan?->name,
                'package_tier'   => $sub->plan?->tier,
                'billing_cycle'  => $sub->billing_cycle,
                'amount_paid'    => (float) $sub->amount_paid,
                'start_date'     => $sub->start_date?->toDateString(),
                'end_date'       => $sub->end_date?->toDateString(),
                'days_remaining' => $sub->daysRemaining(),
                'auto_renew'     => $sub->auto_renew,
                'created_at'     => $sub->created_at?->toIso8601String(),
            ],
        ]);
    }

    // ── Create subscription ───────────────────────────────────────────────────

    public function createSubscription(Request $request): JsonResponse
    {
        $request->validate([
            'package_id'        => 'required|exists:membership_plans,id',
            'billing_cycle'     => 'required|in:monthly,annual',
            'payment_reference' => 'required|string',
            'amount_paid'       => 'required|numeric|min:0',
        ]);

        $user = $request->user();
        $plan = MembershipPlan::findOrFail($request->package_id);

        // Cancel any existing active subscription
        MobileSubscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->update(['status' => 'cancelled']);

        $months     = $request->billing_cycle === 'annual' ? 12 : 1;
        $startDate  = now()->toDateString();
        $endDate    = now()->addMonths($months)->toDateString();

        $sub = MobileSubscription::create([
            'user_id'           => $user->id,
            'plan_id'           => $plan->id,
            'status'            => 'active',
            'billing_cycle'     => $request->billing_cycle,
            'amount_paid'       => $request->amount_paid,
            'payment_reference' => $request->payment_reference,
            'start_date'        => $startDate,
            'end_date'          => $endDate,
        ]);

        // Generate QR token immediately
        QrToken::where('user_id', $user->id)->delete();
        QrToken::create([
            'user_id'    => $user->id,
            'token'      => Str::random(64),
            'expires_at' => now()->endOfDay(),
        ]);

        return response()->json([
            'data' => [
                'id'           => $sub->id,
                'status'       => $sub->status,
                'package_name' => $plan->name,
                'package_tier' => $plan->tier,
                'start_date'   => $startDate,
                'end_date'     => $endDate,
            ],
            'message' => 'Subscription activated successfully.',
        ], 201);
    }

    // ── Cancel subscription ───────────────────────────────────────────────────

    public function cancelSubscription(Request $request): JsonResponse
    {
        $sub = MobileSubscription::where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->first();

        if (!$sub) {
            return response()->json(['message' => 'No active subscription found.'], 404);
        }

        $sub->update(['status' => 'cancelled']);

        return response()->json([
            'message' => "Subscription cancelled. Access continues until {$sub->end_date->toDateString()}.",
        ]);
    }

    // ── Gyms list ─────────────────────────────────────────────────────────────

    public function gyms(Request $request): JsonResponse
    {
        $perPage = min((int) ($request->per_page ?? 20), 50);

        $gyms = Gym::where('is_active', true)
            ->when($request->category, fn($q) => $q->where('category', $request->category))
            ->when($request->tier, fn($q) => $q->where('tier', $request->tier))
            ->when($request->search, fn($q) => $q->where(function ($sub) use ($request) {
                $sub->where('name', 'like', "%{$request->search}%")
                    ->orWhere('address', 'like', "%{$request->search}%")
                    ->orWhere('sub_city', 'like', "%{$request->search}%");
            }))
            ->orderByDesc('quality_score')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($gyms->items())->map(fn($g) => [
                'id'            => $g->id,
                'name'          => $g->name,
                'category'      => $g->category ?? 'gym',
                'tier'          => $g->tier,
                'address'       => $g->address,
                'sub_city'      => $g->sub_city,
                'city'          => $g->city,
                'latitude'      => $g->latitude,
                'longitude'     => $g->longitude,
                'photo_url'     => $g->photo_url,
                'cover_photo_url' => $g->cover_photo_url,
                'quality_score' => $g->quality_score ?? 50,
                'is_active'     => $g->is_active,
            ]),
            'meta' => [
                'current_page' => $gyms->currentPage(),
                'last_page'    => $gyms->lastPage(),
                'total'        => $gyms->total(),
                'per_page'     => $gyms->perPage(),
            ],
        ]);
    }

    // ── Gym detail ────────────────────────────────────────────────────────────

    public function gymDetail(Gym $gym): JsonResponse
    {
        return response()->json([
            'data' => [
                'id'              => $gym->id,
                'name'            => $gym->name,
                'category'        => $gym->category ?? 'gym',
                'tier'            => $gym->tier,
                'description'     => null,
                'address'         => $gym->address,
                'sub_city'        => $gym->sub_city,
                'city'            => $gym->city,
                'latitude'        => $gym->latitude,
                'longitude'       => $gym->longitude,
                'phone'           => $gym->contact_phone,
                'email'           => $gym->contact_email,
                'photo_url'       => $gym->photo_url,
                'cover_photo_url' => $gym->cover_photo_url,
                'amenities'       => $gym->amenities ?? [],
                'facilities'      => $gym->facilities ?? [],
                'opening_hours'   => $gym->opening_hours ?? [],
                'quality_score'   => $gym->quality_score ?? 50,
                'max_capacity'    => $gym->max_capacity,
                'partner_code'    => $gym->partner_code,
                'is_active'       => $gym->is_active,
            ],
        ]);
    }

    // ── Notifications ─────────────────────────────────────────────────────────

    public function notifications(Request $request): JsonResponse
    {
        $perPage = min((int) ($request->per_page ?? 20), 50);

        $notifications = Notification::where('user_id', $request->user()->id)
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'data' => collect($notifications->items())->map(fn($n) => [
                'id'         => $n->id,
                'title'      => $n->title,
                'body'       => $n->body,
                'type'       => $n->type,
                'is_read'    => $n->is_read,
                'created_at' => $n->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page'    => $notifications->lastPage(),
                'total'        => $notifications->total(),
                'per_page'     => $notifications->perPage(),
            ],
        ]);
    }

    // ── Mark all notifications read ───────────────────────────────────────────

    public function markAllNotificationsRead(Request $request): JsonResponse
    {
        Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    // ── Mark single notification read ─────────────────────────────────────────

    public function markNotificationRead(Request $request, Notification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $notification->update(['is_read' => true, 'read_at' => now()]);
        return response()->json(['message' => 'Notification marked as read.']);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function accessibleTiers(string $tier): array
    {
        return match ($tier) {
            'platinum'   => ['basic', 'basic_plus', 'platinum', 'premium'],
            'basic_plus' => ['basic', 'basic_plus'],
            default      => ['basic'],
        };
    }
}
