<?php

namespace App\Http\Controllers;

use App\Models\Checkin;
use App\Models\Gym;
use App\Models\GymStaff;
use App\Models\MobileSubscription;
use App\Models\Notification;
use App\Models\PartnerPayout;
use App\Models\QrToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Mail\StaffInviteMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PartnerMobileController extends Controller
{
    // ── Get authenticated staff's gym ─────────────────────────────────────────

    private function getMyGym(Request $request): ?Gym
    {
        $gymStaff = GymStaff::where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->first();

        if (!$gymStaff) {
            // Fallback: gym linked by contact email (existing partner portal logic)
            return Gym::where('contact_email', $request->user()->email)
                ->where('is_active', true)
                ->first();
        }

        return Gym::find($gymStaff->gym_id);
    }

    private function getMyStaffRecord(Request $request): ?GymStaff
    {
        return GymStaff::where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->first();
    }

    private function isOwner(Request $request): bool
    {
        $staff = $this->getMyStaffRecord($request);
        return $staff?->role === 'admin';
    }

    // ── Partner Me ────────────────────────────────────────────────────────────

    public function me(Request $request): JsonResponse
    {
        $user      = $request->user();
        $gymStaff  = $this->getMyStaffRecord($request);
        $gym       = $this->getMyGym($request);

        return response()->json([
            'data' => [
                'user' => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'role'  => $user->role,
                ],
                'gym' => $gym ? [
                    'id'             => $gym->id,
                    'name'           => $gym->name,
                    'category'       => $gym->category ?? 'gym',
                    'tier'           => $gym->tier,
                    'address'        => $gym->address,
                    'per_visit_rate' => (float) ($gym->per_visit_rate ?? 0),
                ] : null,
                'staff_role'           => $gymStaff?->role ?? 'staff',
                'must_change_password' => $gymStaff?->must_change_password ?? $user->must_change_password ?? false,
            ],
        ]);
    }

    // ── SCAN — Validate QR and record check-in ────────────────────────────────
    // Enforces: token validity → member status → subscription → tier → 12hr → monthly cap

    public function scan(Request $request): JsonResponse
    {
        $request->validate([
            'qr_token' => 'required|string',
            'gym_id'   => 'required|integer',
        ]);

        $gym = Gym::where('id', $request->gym_id)->where('is_active', true)->first();
        if (!$gym) {
            return response()->json([
                'data' => ['valid' => false, 'reason' => 'Facility not found or inactive.'],
            ]);
        }

        // ── 1. Validate QR token ──────────────────────────────────────────────
        $qrToken = QrToken::where('token', $request->qr_token)
            ->where('expires_at', '>', now())
            ->first();

        if (!$qrToken) {
            return response()->json([
                'data' => [
                    'valid'  => false,
                    'reason' => 'Invalid or expired QR code. Ask the member to refresh their QR.',
                ],
            ]);
        }

        $member = User::find($qrToken->user_id);

        // ── 2. Member active check ────────────────────────────────────────────
        if (!$member || !$member->is_active) {
            return response()->json([
                'data' => ['valid' => false, 'reason' => 'Member account is suspended.'],
            ]);
        }

        // ── 3. Find active subscription (B2C or B2B employee) ────────────────
        $subscription = MobileSubscription::with('plan')
            ->where('user_id', $member->id)
            ->where('status', 'active')
            ->where('end_date', '>=', now())
            ->latest()
            ->first();

        $planTier    = null;
        $planName    = null;
        $employeeId  = null;
        $memberCode  = $member->member_code ?? $member->fan_number;

        if ($subscription) {
            $planTier = $subscription->plan?->tier;
            $planName = $subscription->plan?->name;
        } else {
            // Check B2B employee membership
            $empMembership = DB::table('memberships')
                ->join('employees', 'memberships.employee_id', '=', 'employees.id')
                ->join('membership_plans', 'memberships.plan_id', '=', 'membership_plans.id')
                ->where('employees.user_id', $member->id)
                ->where('memberships.status', 'active')
                ->where('memberships.end_date', '>=', now()->toDateString())
                ->select('memberships.id as membership_id', 'memberships.employee_id', 'membership_plans.tier', 'membership_plans.name')
                ->first();

            if (!$empMembership) {
                return response()->json([
                    'data' => [
                        'valid'  => false,
                        'reason' => 'No active subscription. Member must purchase a plan.',
                    ],
                ]);
            }

            $planTier   = $empMembership->tier;
            $planName   = $empMembership->name;
            $employeeId = $empMembership->employee_id;
        }

        // ── 4. Tier access check ──────────────────────────────────────────────
        $tierRank = ['basic' => 1, 'basic_plus' => 2, 'premium' => 3, 'platinum' => 3];
        $memberRank = $tierRank[$planTier] ?? 0;
        $gymRank    = $tierRank[$gym->tier] ?? 0;

        if ($memberRank < $gymRank) {
            $required = match ($gym->tier) {
                'platinum', 'premium' => 'Platinum',
                'basic_plus'          => 'Basic Plus or Platinum',
                default               => 'Basic',
            };
            return response()->json([
                'data' => [
                    'valid'  => false,
                    'reason' => "This facility requires a {$required} plan. Member needs to upgrade.",
                ],
            ]);
        }

        // ── 5. 12-HOUR RULE ───────────────────────────────────────────────────
        $lastCheckin = Checkin::where('gym_id', $gym->id)
            ->where(function ($q) use ($member, $employeeId) {
                $q->where('user_id', $member->id);
                if ($employeeId) {
                    $q->orWhere('employee_id', $employeeId);
                }
            })
            ->orderByDesc('checked_in_at')
            ->first();

        if ($lastCheckin) {
            $hoursSince = Carbon::parse($lastCheckin->checked_in_at)->diffInMinutes(now()) / 60;
            if ($hoursSince < 12) {
                $hoursRemaining = round(12 - $hoursSince, 1);
                return response()->json([
                    'data' => [
                        'valid'  => false,
                        'reason' => "You already checked in here today. You can check in again in {$hoursRemaining} hours.",
                    ],
                ]);
            }
        }

        // ── 6. Monthly cap check ──────────────────────────────────────────────
        $monthlyCount = Checkin::where('gym_id', $gym->id)
            ->where(function ($q) use ($member, $employeeId) {
                $q->where('user_id', $member->id);
                if ($employeeId) {
                    $q->orWhere('employee_id', $employeeId);
                }
            })
            ->whereYear('checked_in_at', now()->year)
            ->whereMonth('checked_in_at', now()->month)
            ->count();

        $maxMonthly = 20; // default cap — can be plan-specific
        if ($monthlyCount >= $maxMonthly) {
            return response()->json([
                'data' => [
                    'valid'  => false,
                    'reason' => "Monthly visit limit reached for this facility ({$monthlyCount} visits used). Limit resets on the 1st.",
                ],
            ]);
        }

        // ── 7. Record check-in ────────────────────────────────────────────────
        $checkin = Checkin::create([
            'gym_id'        => $gym->id,
            'employee_id'   => $employeeId,
            'user_id'       => $member->id,
            'checked_in_at' => now(),
            'method'        => 'qr_code',
            'recorded_by'   => $request->user()->name,
            'membership_id' => $subscription?->id ?? ($empMembership->membership_id ?? null),
        ]);

        $visitsThisMonth = $monthlyCount + 1;
        $visitsRemaining = max(0, $maxMonthly - $visitsThisMonth);

        return response()->json([
            'data' => [
                'valid'            => true,
                'checkin_id'       => $checkin->id,
                'member_name'      => $member->name,
                'member_code'      => $memberCode,
                'package_name'     => $planName,
                'package_tier'     => $planTier,
                'visits_this_month'=> $visitsThisMonth,
                'visits_remaining' => $visitsRemaining,
                'checked_in_at'    => $checkin->checked_in_at->toIso8601String(),
            ],
        ]);
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function dashboard(Request $request): JsonResponse
    {
        $gym = $this->getMyGym($request);
        if (!$gym) {
            return response()->json(['message' => 'No gym linked to this account.'], 403);
        }

        $now        = now();
        $monthStart = $now->copy()->startOfMonth();
        $todayStart = $now->copy()->startOfDay();

        $todayCheckins = Checkin::where('gym_id', $gym->id)
            ->where('checked_in_at', '>=', $todayStart)
            ->get();

        $monthCheckins = Checkin::where('gym_id', $gym->id)
            ->where('checked_in_at', '>=', $monthStart)
            ->get();

        $recentCheckins = Checkin::where('gym_id', $gym->id)
            ->orderByDesc('checked_in_at')
            ->limit(10)
            ->get()
            ->map(fn($c) => [
                'id'           => $c->id,
                'member_name'  => User::find($c->user_id)?->name ?? 'Unknown',
                'member_code'  => User::find($c->user_id)?->member_code ?? $c->recorded_by,
                'package_name' => null,
                'package_tier' => null,
                'checked_in_at'=> $c->checked_in_at?->toIso8601String(),
            ]);

        $estimatedPayout = $monthCheckins->count() * (float) ($gym->per_visit_rate ?? 0);

        return response()->json([
            'data' => [
                'gym' => [
                    'id'             => $gym->id,
                    'name'           => $gym->name,
                    'category'       => $gym->category ?? 'gym',
                    'tier'           => $gym->tier,
                    'address'        => $gym->address,
                    'per_visit_rate' => (float) ($gym->per_visit_rate ?? 0),
                ],
                'stats' => [
                    'today_checkins'    => $todayCheckins->count(),
                    'today_unique'      => $todayCheckins->pluck('user_id')->unique()->count(),
                    'month_checkins'    => $monthCheckins->count(),
                    'month_unique'      => $monthCheckins->pluck('user_id')->unique()->count(),
                    'estimated_payout'  => round($estimatedPayout, 2),
                    'last_checkin_at'   => Checkin::where('gym_id', $gym->id)
                        ->latest('checked_in_at')->value('checked_in_at'),
                ],
                'recent_checkins' => $recentCheckins->values(),
            ],
        ]);
    }

    // ── Today's visits ────────────────────────────────────────────────────────

    public function visitsToday(Request $request): JsonResponse
    {
        $gym = $this->getMyGym($request);
        if (!$gym) return response()->json(['message' => 'No gym linked.'], 403);

        $checkins = Checkin::where('gym_id', $gym->id)
            ->where('checked_in_at', '>=', now()->startOfDay())
            ->orderByDesc('checked_in_at')
            ->get();

        $data = $checkins->map(function ($c) {
            $user     = User::find($c->user_id);
            $duration = null;
            if ($c->checked_in_at && $c->checked_out_at) {
                $duration = Carbon::parse($c->checked_in_at)
                    ->diffInMinutes(Carbon::parse($c->checked_out_at));
            }
            return [
                'id'               => $c->id,
                'member_name'      => $user?->name ?? 'Unknown',
                'member_code'      => $user?->member_code ?? $user?->fan_number,
                'package_name'     => null,
                'package_tier'     => null,
                'checked_in_at'    => $c->checked_in_at?->toIso8601String(),
                'checked_out_at'   => $c->checked_out_at?->toIso8601String(),
                'duration_minutes' => $duration,
            ];
        });

        return response()->json([
            'data'    => $data->values(),
            'summary' => [
                'total'          => $checkins->count(),
                'unique_members' => $checkins->pluck('user_id')->filter()->unique()->count(),
            ],
        ]);
    }

    // ── Monthly visits ────────────────────────────────────────────────────────

    public function visitsMonthly(Request $request): JsonResponse
    {
        $gym = $this->getMyGym($request);
        if (!$gym) return response()->json(['message' => 'No gym linked.'], 403);

        $month = $request->month ?? now()->format('Y-m');
        [$year, $mon] = explode('-', $month);

        $checkins = Checkin::where('gym_id', $gym->id)
            ->whereYear('checked_in_at', $year)
            ->whereMonth('checked_in_at', $mon)
            ->orderByDesc('checked_in_at')
            ->get();

        $data = $checkins->map(function ($c) {
            $user     = User::find($c->user_id);
            $duration = null;
            if ($c->checked_in_at && $c->checked_out_at) {
                $duration = Carbon::parse($c->checked_in_at)
                    ->diffInMinutes(Carbon::parse($c->checked_out_at));
            }
            return [
                'id'               => $c->id,
                'member_name'      => $user?->name ?? 'Unknown',
                'member_code'      => $user?->member_code ?? $user?->fan_number,
                'package_name'     => null,
                'package_tier'     => null,
                'checked_in_at'    => $c->checked_in_at?->toIso8601String(),
                'checked_out_at'   => $c->checked_out_at?->toIso8601String(),
                'duration_minutes' => $duration,
            ];
        });

        $activeDays = $checkins->groupBy(fn($c) => Carbon::parse($c->checked_in_at)->toDateString())->count();

        return response()->json([
            'data'    => $data->values(),
            'summary' => [
                'total'          => $checkins->count(),
                'unique_members' => $checkins->pluck('user_id')->filter()->unique()->count(),
                'active_days'    => $activeDays,
            ],
        ]);
    }

    // ── Reports ───────────────────────────────────────────────────────────────

    public function reports(Request $request): JsonResponse
    {
        $gym = $this->getMyGym($request);
        if (!$gym) return response()->json(['message' => 'No gym linked.'], 403);

        $month = $request->month ?? now()->format('Y-m');
        [$year, $mon] = explode('-', $month);

        $checkins = Checkin::where('gym_id', $gym->id)
            ->whereYear('checked_in_at', $year)
            ->whereMonth('checked_in_at', $mon)
            ->get();

        $total         = $checkins->count();
        $uniqueMembers = $checkins->pluck('user_id')->filter()->unique()->count();
        $days          = $checkins->groupBy(fn($c) => Carbon::parse($c->checked_in_at)->toDateString());
        $activeDays    = $days->count();
        $avgPerDay     = $activeDays > 0 ? round($total / $activeDays, 1) : 0;
        $peakDay       = $days->sortByDesc(fn($d) => $d->count())->keys()->first();
        $peakDayCount  = $days->max(fn($d) => $d->count());

        // Daily activity last 30 days in this month
        $dailyActivity = [];
        $daysInMonth = Carbon::createFromDate($year, $mon, 1)->daysInMonth;
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dateStr = sprintf('%04d-%02d-%02d', $year, $mon, $d);
            $dailyActivity[] = [
                'date'  => $dateStr,
                'day'   => Carbon::parse($dateStr)->format('D'),
                'count' => $days->get($dateStr)?->count() ?? 0,
            ];
        }

        // Top members
        $topMembers = $checkins->groupBy('user_id')
            ->map(fn($group, $userId) => [
                'member_name' => User::find($userId)?->name ?? 'Unknown',
                'member_code' => User::find($userId)?->member_code ?? '',
                'visits'      => $group->count(),
            ])
            ->sortByDesc('visits')
            ->take(5)
            ->values();

        $estimatedPayout = $total * (float) ($gym->per_visit_rate ?? 0);

        return response()->json([
            'data' => [
                'period' => Carbon::createFromDate($year, $mon, 1)->format('F Y'),
                'gym'    => [
                    'name'           => $gym->name,
                    'per_visit_rate' => (float) ($gym->per_visit_rate ?? 0),
                    'tier'           => $gym->tier,
                ],
                'summary' => [
                    'total_checkins'  => $total,
                    'unique_members'  => $uniqueMembers,
                    'active_days'     => $activeDays,
                    'avg_per_day'     => $avgPerDay,
                    'peak_day'        => $peakDay,
                    'peak_day_count'  => $peakDayCount ?? 0,
                ],
                'estimated_payout' => [
                    'checkins'       => $total,
                    'rate_per_visit' => (float) ($gym->per_visit_rate ?? 0),
                    'total'          => round($estimatedPayout, 2),
                    'status'         => 'pending',
                ],
                'daily_activity' => $dailyActivity,
                'top_members'    => $topMembers,
            ],
        ]);
    }

    // ── Financials ────────────────────────────────────────────────────────────

    public function financials(Request $request): JsonResponse
    {
        $gym = $this->getMyGym($request);
        if (!$gym) return response()->json(['message' => 'No gym linked.'], 403);

        $now           = now();
        $monthCheckins = Checkin::where('gym_id', $gym->id)
            ->whereYear('checked_in_at', $now->year)
            ->whereMonth('checked_in_at', $now->month)
            ->count();

        $estimatedAmount = $monthCheckins * (float) ($gym->per_visit_rate ?? 0);

        $payouts = PartnerPayout::where('gym_id', $gym->id)
            ->orderByDesc('period_start')
            ->get()
            ->map(fn($p) => [
                'id'           => $p->id,
                'period'       => Carbon::parse($p->period_start)->format('F Y'),
                'period_start' => $p->period_start?->toDateString(),
                'period_end'   => $p->period_end?->toDateString(),
                'total_checkins' => $p->total_checkins,
                'total_amount'   => (float) $p->total_amount,
                'status'         => $p->status,
                'paid_at'        => $p->paid_at?->toIso8601String(),
            ]);

        return response()->json([
            'data' => [
                'agreement' => [
                    'per_visit_rate'                => (float) ($gym->per_visit_rate ?? 0),
                    'tier'                          => $gym->tier,
                    'tier_name'                     => ucwords(str_replace('_', ' ', $gym->tier)),
                    'max_visits_per_member_per_month'=> 20,
                    'payout_schedule'               => 'monthly',
                ],
                'current_month' => [
                    'period'           => $now->format('F Y'),
                    'checkins'         => $monthCheckins,
                    'estimated_amount' => round($estimatedAmount, 2),
                    'status'           => 'pending',
                ],
                'payout_history' => $payouts->values(),
            ],
        ]);
    }

    // ── Staff list ────────────────────────────────────────────────────────────

    public function staffList(Request $request): JsonResponse
    {
        if (!$this->isOwner($request)) {
            return response()->json(['message' => 'Owner access required.'], 403);
        }

        $gym   = $this->getMyGym($request);
        $staff = GymStaff::with('user')
            ->where('gym_id', $gym->id)
            ->get()
            ->map(fn($s) => [
                'id'                   => $s->id,
                'user_id'              => $s->user_id,
                'name'                 => $s->user?->name ?? 'Unknown',
                'email'                => $s->user?->email,
                'phone'                => $s->user?->phone,
                'role'                 => $s->role,
                'is_active'            => $s->is_active,
                'must_change_password' => $s->must_change_password,
                'joined_at'            => $s->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $staff->values()]);
    }

    // ── Invite staff ──────────────────────────────────────────────────────────

    public function inviteStaff(Request $request): JsonResponse
    {
        if (!$this->isOwner($request)) {
            return response()->json(['message' => 'Owner access required.'], 403);
        }

        $request->validate([
            'email' => 'required|email',
            'name'  => 'required|string|max:100',
        ]);

        $gym   = $this->getMyGym($request);
        $email = $request->email;
        $name  = $request->name;

        // Check if this email is already registered
        $existingUser = User::where('email', $email)->first();
        if ($existingUser) {
            // Already staff at this gym
            $alreadyStaff = GymStaff::where('user_id', $existingUser->id)
                ->where('gym_id', $gym->id)
                ->where('is_active', true)
                ->exists();

            if ($alreadyStaff) {
                return response()->json([
                    'message' => 'This person is already a staff member at your gym.',
                ], 422);
            }

            // Staff at a different gym — not allowed
            $otherGym = GymStaff::where('user_id', $existingUser->id)
                ->where('is_active', true)
                ->where('gym_id', '!=', $gym->id)
                ->exists();

            if ($otherGym) {
                return response()->json([
                    'message' => 'This person is already a staff member at another gym and cannot be added to yours.',
                ], 422);
            }

        } else {
            // New user — create account with temp password and send invite email
            $tempPassword = Str::random(10);

            $newUser = User::create([
                'name'      => $name,
                'email'     => $email,
                'password'  => Hash::make($tempPassword),
                'role'      => 'gym_staff',
                'is_active' => true,
            ]);

            GymStaff::create([
                'user_id'              => $newUser->id,
                'gym_id'               => $gym->id,
                'role'                 => 'staff',
                'is_active'            => true,
                'must_change_password' => true,
            ]);

            Mail::to($email)->send(new StaffInviteMail($gym->name, $email, $tempPassword));
        }

        return response()->json([
            'message' => "Invite sent to {$email}. They will receive an email to set up their account.",
        ], 201);
    }

    // ── Remove staff ──────────────────────────────────────────────────────────

    public function removeStaff(Request $request, GymStaff $gymStaff): JsonResponse
    {
        if (!$this->isOwner($request)) {
            return response()->json(['message' => 'Owner access required.'], 403);
        }

        // Cannot remove yourself
        if ($gymStaff->user_id === $request->user()->id) {
            return response()->json(['message' => 'You cannot remove yourself.'], 403);
        }

        $gymStaff->update(['is_active' => false]);

        return response()->json(['message' => 'Staff member removed from your team.']);
    }

    // ── Update facility ────────────────────────────────────────────────────────

    public function updateFacility(Request $request): JsonResponse
    {
        if (!$this->isOwner($request)) {
            return response()->json(['message' => 'Owner access required.'], 403);
        }

        $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'sometimes|string|max:1000',
            'phone'       => 'sometimes|string|max:20',
            'address'     => 'sometimes|string|max:500',
        ]);

        $gym = $this->getMyGym($request);
        if (!$gym) return response()->json(['message' => 'No gym linked.'], 403);

        $gym->update(array_filter([
            'name'          => $request->name,
            'contact_phone' => $request->phone,
            'address'       => $request->address,
        ]));

        return response()->json([
            'data'    => [
                'id'      => $gym->id,
                'name'    => $gym->name,
                'phone'   => $gym->contact_phone,
                'address' => $gym->address,
            ],
            'message' => 'Facility updated successfully.',
        ]);
    }

    // ── Upload facility photo ─────────────────────────────────────────────────

    public function uploadFacilityPhoto(Request $request): JsonResponse
    {
        if (!$this->isOwner($request)) {
            return response()->json(['message' => 'Owner access required.'], 403);
        }

        $request->validate([
            'photo' => 'required|image|mimes:jpg,jpeg,png|max:5120',
            'type'  => 'required|in:photo,cover',
        ]);

        $gym = $this->getMyGym($request);
        if (!$gym) return response()->json(['message' => 'No gym linked.'], 403);

        $field = $request->type === 'cover' ? 'cover_photo_url' : 'photo_url';

        // Delete old photo
        $oldPath = $gym->$field;
        if ($oldPath) Storage::disk('public')->delete(str_replace('/storage/', '', $oldPath));

        $path = $request->file('photo')->store("gym-photos/{$gym->id}", 'public');
        $url  = Storage::disk('public')->url($path);

        $gym->update([$field => $url]);

        return response()->json([
            'data'    => [
                'photo_url'       => $gym->photo_url,
                'cover_photo_url' => $gym->cover_photo_url,
            ],
            'message' => 'Photo uploaded successfully.',
        ]);
    }
}
