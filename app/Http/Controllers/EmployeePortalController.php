<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Gym;
use App\Models\MembershipPlan;
use App\Models\NewsPost;
use App\Models\WellnessProgram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class EmployeePortalController extends Controller
{
    private function getMyEmployee(): Employee
    {
        $employee = Employee::where('user_id', auth()->id())
            ->with(['user', 'company', 'activeMembership', 'checkins.gym'])
            ->first();

        if (!$employee) {
            abort(403, 'No employee record linked to this account.');
        }

        return $employee;
    }

    /**
     * Full check-in history with the details of each visit: date and time in
     * and out, duration, the gym and branch, its address and contact, and how
     * the visit was recorded.
     */
    public function checkins(): JsonResponse
    {
        $employee = Employee::where('user_id', auth()->id())->first();
        if (!$employee) {
            abort(403, 'No employee record linked to this account.');
        }

        $methods = ['card' => 'FitAccess card', 'fan_number' => 'ID number', 'facial' => 'Face check'];
        $tz      = config('app.timezone');

        $rows = \App\Models\Checkin::where('employee_id', $employee->id)
            ->with('gym.parent:id,name')
            ->orderByDesc('checked_in_at')
            ->get()
            ->map(function ($c) use ($methods, $tz) {
                $g   = $c->gym;
                $in  = $c->checked_in_at?->copy()->setTimezone($tz);
                $out = $c->checked_out_at?->copy()->setTimezone($tz);

                return [
                    'id'              => $c->id,
                    'gym_id'          => $g?->id,
                    'gym_name'        => $g ? ($g->parent?->name ?? $g->name) : 'Unknown gym',
                    'branch_name'     => $g ? ($g->branch_name ?: ($g->parent ? $g->name : null)) : null,
                    'gym_tier'        => $g?->tier ?? 'basic',
                    'gym_sub_city'    => $g?->sub_city ?? '',
                    'gym_city'        => $g?->city,
                    'gym_address'     => $g?->address,
                    'gym_phone'       => $g?->contact_phone,
                    'latitude'        => $g?->latitude !== null ? (float) $g->latitude : null,
                    'longitude'       => $g?->longitude !== null ? (float) $g->longitude : null,
                    'checked_in_at'   => $in?->format('Y-m-d H:i'),
                    'checked_out_at'  => $out?->format('Y-m-d H:i'),
                    'date'            => $in?->format('d/m/Y'),
                    'weekday'         => $in?->format('l'),
                    'time_in'         => $in?->format('H:i'),
                    'time_out'        => $out?->format('H:i'),
                    'duration_min'    => $c->duration_minutes,
                    'status'          => $c->checked_out_at ? 'completed' : ($in && $in->isToday() ? 'in_gym' : 'no_checkout'),
                    'method'          => $c->method,
                    'method_label'    => $methods[$c->method] ?? ucfirst(str_replace('_', ' ', (string) $c->method)),
                    'recorded_by'     => $c->recorded_by,
                ];
            })->values();

        $now = Carbon::now();
        return response()->json([
            'checkins' => $rows,
            'stats'    => [
                'total_checkins'      => $rows->count(),
                'this_month_checkins' => $rows->filter(fn ($r) => $r['checked_in_at'] && Carbon::parse($r['checked_in_at'])->isSameMonth($now))->count(),
                'this_week_checkins'  => $rows->filter(fn ($r) => $r['checked_in_at'] && Carbon::parse($r['checked_in_at'])->isSameWeek($now))->count(),
                'gyms_visited'        => $rows->pluck('gym_id')->filter()->unique()->count(),
            ],
        ]);
    }

    public function dashboard(): JsonResponse
    {
        $employee = $this->getMyEmployee();
        $user     = $employee->user;
        $company  = $employee->company;
        $membership = $employee->activeMembership;

        // ── Resolve the employee's package ──────────────────────────
        // HR's package choice is saved as the employee level (chief = Premium …);
        // App\Support\Tier is the single rule shared with check-in and billing.
        $canonicalTier  = \App\Support\Tier::forLevel($employee->level ?? 'staff');
        $allActivePlans = MembershipPlan::where('is_active', true)->orderBy('monthly_fee_etb')->get();

        $myPlan = \App\Services\MembershipService::resolvePlan($canonicalTier);

        $planKey   = $canonicalTier;
        $planName  = $myPlan?->name ?? ucwords(str_replace('_', ' ', $canonicalTier));
        $planFee   = (float) ($myPlan?->monthly_fee_etb ?? 0);
        $myGymTier = $canonicalTier;

        // Higher packages include the lower ones' gyms (Premium → all gyms)
        $accessibleTiers = \App\Support\Tier::gymTiersFor($canonicalTier);

        // Plan name for each gym tier, for the tier pills and the filter
        // (keyed by canonical tier: basic / basic_plus / premium, each once)
        $displayTiers = array_values(array_unique(array_map([\App\Support\Tier::class, 'canonical'], $accessibleTiers)));
        $accessiblePlanLabels = [];
        foreach ($displayTiers as $t) {
            $label = $allActivePlans->first(fn($p) => \App\Support\Tier::canonical($p->tier) === $t)?->name;
            $accessiblePlanLabels[$t] = $label ?? ucwords(str_replace('_', ' ', $t));
        }

        // ── Check-in stats ──────────────────────────────────────────
        $allCheckins   = $employee->checkins;
        $now           = Carbon::now();
        $thisMonthCheckins = $allCheckins->filter(fn($c) => $c->checked_in_at?->isCurrentMonth())->count();
        $thisWeekCheckins  = $allCheckins->filter(fn($c) => $c->checked_in_at?->isCurrentWeek())->count();
        $lastCheckin       = $allCheckins->sortByDesc('checked_in_at')->first();

        // ── Recent check-ins (last 8) ───────────────────────────────
        $recentCheckins = $allCheckins
            ->sortByDesc('checked_in_at')
            ->take(8)
            ->map(fn($c) => [
                'id'             => $c->id,
                'gym_name'       => $c->gym?->name ?? 'Unknown Gym',
                'gym_tier'       => $c->gym?->tier ?? 'basic',
                'gym_sub_city'   => $c->gym?->sub_city ?? '',
                'checked_in_at'  => $c->checked_in_at?->format('Y-m-d H:i'),
                'checked_out_at' => $c->checked_out_at?->format('Y-m-d H:i'),
                'duration_min'   => $c->duration_minutes,
            ])->values();

        // ── Accessible gyms based on plan ───────────────────────────
        $foundGyms = Gym::where('is_active', true)
            ->whereIn('tier', $accessibleTiers)
            ->orderBy('name')
            ->get(['id', 'name', 'tier', 'sub_city', 'city', 'address', 'latitude', 'longitude', 'facilities', 'opening_hours', 'max_capacity']);
        $crowd = Gym::crowdFor($foundGyms);

        $gyms = $foundGyms
            ->map(fn($g) => [
                'crowd'        => $crowd[$g->id] ?? null,
                'id'           => $g->id,
                'name'         => $g->name,
                'tier'         => $g->tier,
                'sub_city'     => $g->sub_city,
                'city'         => $g->city,
                'address'      => $g->address,
                'latitude'     => $g->latitude !== null ? (float) $g->latitude : null,
                'longitude'    => $g->longitude !== null ? (float) $g->longitude : null,
                'facilities'   => is_array($g->facilities) ? $g->facilities : [],
                'opening_hours'=> $g->opening_hours,
            ]);

        return response()->json([
            'profile' => [
                'name'       => $user?->name,
                'email'      => $user?->email,
                'phone'      => $user?->phone,
                'fan_number' => $employee->fan_number,
                // Hidden until the employee enters it (HR sends it to them)
                'card_number' => $employee->hasActivatedCard() ? $employee->card_number : null,
                'job_title'  => $employee->job_title,
                'department' => $employee->department,
                'branch'     => $employee->branch,
                'photo_path' => $employee->photo_path,
            ],
            'company' => [
                'name' => $company?->name,
                'city' => $company?->city,
            ],
            'pass' => [
                'plan_name'       => $planName,
                'plan_key'        => $planKey,
                'status'          => $employee->accessStatus(),
                'valid_until'     => $employee->paid_until?->toDateString(),
                'access_message'  => $employee->hasPaidAccess() ? null : $employee->paidAccessMessage(),
                'accessible_tiers'=> $displayTiers,
                'plan_labels'     => $accessiblePlanLabels,
            ],
            'stats' => [
                'total_checkins'      => $allCheckins->count(),
                'this_month_checkins' => $thisMonthCheckins,
                'this_week_checkins'  => $thisWeekCheckins,
                'last_visit'          => $lastCheckin?->checked_in_at?->format('Y-m-d'),
            ],
            'recent_checkins' => $recentCheckins,
            'accessible_gyms' => $gyms,
            'card'            => $this->cardPayload($employee),
            'featured'        => NewsPost::visibleTo($employee->company_id)->where('is_featured', true)
                ->orderByDesc('published_at')->orderByDesc('id')->limit(8)->get()
                ->map(fn (NewsPost $n) => $this->newsPayload($n))->values(),
            'news'            => NewsPost::visibleTo($employee->company_id)
                ->orderByDesc('published_at')->orderByDesc('id')->limit(8)->get()
                ->map(fn (NewsPost $n) => $this->newsPayload($n))->values(),
            'trainings'       => $this->trainingsQuery()->limit(8)->get()
                ->map(fn (WellnessProgram $t) => $this->trainingPayload($t, $employee))->values(),
        ]);
    }

    // ── Card activation ─────────────────────────────────────────────

    private function cardPayload(Employee $employee): array
    {
        $activated = $employee->hasActivatedCard();

        return [
            'activated'    => $activated,
            // HR approved, FitAccess final approval still pending: card can't be activated yet
            'awaiting_approval' => !$activated && $employee->isAwaitingAdminApproval(),
            // Banned: same card number, but it can't be used until the ban is lifted
            'banned'       => $employee->is_banned,
            'banned_text'  => $employee->is_banned ? $employee->banUntilText() : null,
            'number'       => $activated ? \App\Support\CardNumber::format($employee->card_number) : null,
            'active_since' => $employee->card_activated_at?->toDateString(),
            'company'      => $employee->company?->name,
            'status'       => $employee->accessStatus(),
        ];
    }

    /**
     * The employee types the card number HR sent them. Only their own card
     * matches; repeated wrong entries lock activation for 15 minutes.
     */
    public function activateCard(Request $request): JsonResponse
    {
        $request->validate(['card_number' => 'required|string|max:32']);
        $employee = $this->getMyEmployee();

        if ($employee->hasActivatedCard()) {
            return response()->json(['message' => 'Your card is already active.', 'card' => $this->cardPayload($employee)]);
        }

        if ($employee->isAwaitingAdminApproval() || !$employee->user?->is_active) {
            return response()->json([
                'message' => 'Your account is still waiting for FitAccess final approval. You can activate your card once it is approved.',
                'code'    => 'awaiting_admin_approval',
            ], 403);
        }

        if ($employee->is_banned) {
            return response()->json([
                'message' => 'Your card is suspended ' . $employee->banUntilText() . '. Please contact your HR team.',
                'code'    => 'card_banned',
            ], 403);
        }
        if (!$employee->isCardIssued()) {
            return response()->json([
                'message' => 'Your card number has not been sent yet. FitAccess e-mails it to you once your membership is paid.',
                'code'    => 'card_not_issued',
            ], 403);
        }

        $lockKey  = "card_activation_lock:{$employee->id}";
        $countKey = "card_activation_fails:{$employee->id}";
        if (Cache::has($lockKey)) {
            return response()->json(['message' => 'Too many wrong attempts. Please try again in 15 minutes.'], 429);
        }

        $entered = \App\Support\CardNumber::normalize($request->input('card_number'));
        if (!$entered || !$employee->card_number || !hash_equals($employee->card_number, $entered)) {
            $fails = (int) Cache::get($countKey, 0) + 1;
            Cache::put($countKey, $fails, now()->addMinutes(15));
            if ($fails >= 5) {
                Cache::put($lockKey, true, now()->addMinutes(15));
                Cache::forget($countKey);
            }
            throw ValidationException::withMessages([
                'card_number' => [$entered
                    ? 'This card number does not match your account. Check the number your company sent you.'
                    : 'Enter the 12-digit card number exactly as you received it.'],
            ]);
        }

        Cache::forget($countKey);
        $employee->update(['card_activated_at' => now()]);
        AuditLog::record('updated', $employee, ['card_activated_at' => null], ['card_activated_at' => $employee->card_activated_at]);

        return response()->json([
            'message' => 'Your FitAccess card is now active.',
            'card'    => $this->cardPayload($employee->fresh('company')),
        ]);
    }

    // ── News ────────────────────────────────────────────────────────

    private function newsPayload(NewsPost $n, bool $withBody = false): array
    {
        return array_filter([
            'id'           => $n->id,
            'title'        => $n->title,
            'category'     => $n->category,
            'excerpt'      => $n->excerpt,
            'body'         => $withBody ? $n->body : null,
            'image_url'    => $n->imageUrl(),
            'link_url'     => $n->link_url,
            'link_label'   => $n->link_label,
            'published_at' => ($n->published_at ?? $n->created_at)?->toDateString(),
        ], fn ($v, $k) => $k !== 'body' || $withBody, ARRAY_FILTER_USE_BOTH);
    }

    public function news(Request $request): JsonResponse
    {
        $employee = $this->getMyEmployee();
        $base     = NewsPost::visibleTo($employee->company_id);

        $items = (clone $base)
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate(24);

        return response()->json([
            'data'       => collect($items->items())->map(fn (NewsPost $n) => $this->newsPayload($n))->values(),
            'categories' => (clone $base)->distinct()->orderBy('category')->pluck('category')->values(),
            'meta'       => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total()],
        ]);
    }

    public function newsShow(NewsPost $newsPost): JsonResponse
    {
        $employee = $this->getMyEmployee();
        $visible  = NewsPost::visibleTo($employee->company_id)->whereKey($newsPost->id)->exists();
        abort_unless($visible, 404);

        return response()->json($this->newsPayload($newsPost, true));
    }

    // ── Trainings ───────────────────────────────────────────────────

    private function trainingsQuery()
    {
        return WellnessProgram::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString()))
            ->withCount(['employees as participants_count' => fn ($q) => $q->where('employee_wellness.status', 'enrolled')])
            ->orderByDesc('created_at');
    }

    private function trainingPayload(WellnessProgram $t, Employee $employee, bool $full = false): array
    {
        $joined = $t->relationLoaded('employees')
            ? $t->employees->contains('id', $employee->id)
            : $t->employees()->where('employees.id', $employee->id)->wherePivot('status', 'enrolled')->exists();

        $payload = [
            'id'               => $t->id,
            'title'            => $t->title,
            'category'         => $t->category,
            'image_url'        => $t->imageUrl(),
            'duration_minutes' => $t->duration_minutes,
            'is_online'        => $t->is_online,
            'location'         => $t->location,
            'start_date'       => $t->start_date?->toDateString(),
            'end_date'         => $t->end_date?->toDateString(),
            'max_participants' => $t->max_participants,
            'participants'     => $t->participants_count ?? null,
            'joined'           => $joined,
        ];
        if ($full) {
            $payload += [
                'description'      => $t->description,
                'provider'         => $t->provider,
                'provider_contact' => $t->provider_contact,
                'video_url'        => $employee->hasActivatedCard() && !$employee->is_banned ? $t->video_url : null,
            ];
        }

        return $payload;
    }

    public function trainings(Request $request): JsonResponse
    {
        $employee = $this->getMyEmployee();
        $items = $this->trainingsQuery()
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->paginate(24);

        return response()->json([
            'data'       => collect($items->items())->map(fn ($t) => $this->trainingPayload($t, $employee))->values(),
            'categories' => WellnessProgram::where('is_active', true)->distinct()->orderBy('category')->pluck('category')->values(),
            'meta'       => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total()],
        ]);
    }

    public function trainingShow(WellnessProgram $wellnessProgram): JsonResponse
    {
        abort_unless($wellnessProgram->is_active, 404);
        $employee = $this->getMyEmployee();
        $wellnessProgram->loadCount(['employees as participants_count' => fn ($q) => $q->where('employee_wellness.status', 'enrolled')]);

        return response()->json($this->trainingPayload($wellnessProgram, $employee, true));
    }

    public function joinTraining(WellnessProgram $wellnessProgram): JsonResponse
    {
        abort_unless($wellnessProgram->is_active, 404);
        $employee = $this->getMyEmployee();

        if ($wellnessProgram->end_date && $wellnessProgram->end_date->isPast()) {
            return response()->json(['message' => 'This training has ended.'], 422);
        }
        if ($wellnessProgram->max_participants) {
            $taken = $wellnessProgram->employees()->wherePivot('status', 'enrolled')
                ->where('employees.id', '!=', $employee->id)->count();
            if ($taken >= $wellnessProgram->max_participants) {
                return response()->json(['message' => 'This training is full.'], 422);
            }
        }

        $wellnessProgram->employees()->syncWithoutDetaching([
            $employee->id => ['status' => 'enrolled', 'enrolled_at' => now()],
        ]);
        $wellnessProgram->employees()->updateExistingPivot($employee->id, ['status' => 'enrolled']);

        return response()->json(['message' => 'You joined this training.', 'joined' => true]);
    }

    public function leaveTraining(WellnessProgram $wellnessProgram): JsonResponse
    {
        $employee = $this->getMyEmployee();
        $wellnessProgram->employees()->updateExistingPivot($employee->id, ['status' => 'dropped']);

        return response()->json(['message' => 'You left this training.', 'joined' => false]);
    }

    /** Whether the employee's name may appear in the "who is in the gym" list */
    public function gymPrivacy(): JsonResponse
    {
        return response()->json(['show_in_gym_list' => (bool) $this->getMyEmployee()->show_in_gym_list]);
    }

    public function updateGymPrivacy(Request $request): JsonResponse
    {
        $data = $request->validate(['show_in_gym_list' => 'required|boolean']);

        $employee = $this->getMyEmployee();
        $employee->update(['show_in_gym_list' => $data['show_in_gym_list']]);

        return response()->json([
            'show_in_gym_list' => (bool) $employee->show_in_gym_list,
            'message' => $employee->show_in_gym_list
                ? 'Your name can appear in the gym list.'
                : 'Your name is hidden from the gym list. You are still counted in the totals.',
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $employee = $this->getMyEmployee();

        $request->validate([
            'phone'      => 'nullable|string|max:20',
            'job_title'  => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'branch'     => 'nullable|string|max:100',
        ]);

        if ($request->has('phone')) {
            $employee->user?->update(['phone' => $request->phone]);
        }

        $employee->update($request->only(['job_title', 'department', 'branch']));

        return response()->json(['message' => 'Profile updated successfully.']);
    }
}
