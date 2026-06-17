<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCheckinRequest;
use App\Http\Resources\CheckinResource;
use App\Models\AuditLog;
use App\Models\Checkin;
use App\Models\DailyGymSelection;
use App\Models\Employee;
use App\Models\Gym;
use App\Models\Membership;
use App\Models\MembershipPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CheckinController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $checkins = Checkin::with(['employee.user', 'gym'])
            ->when($request->gym_id,      fn($q) => $q->where('gym_id',      $request->gym_id))
            ->when($request->employee_id, fn($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->date,        fn($q) => $q->whereDate('checked_in_at', $request->date))
            ->latest('checked_in_at')
            ->paginate(10);

        return CheckinResource::collection($checkins);
    }

    public function store(StoreCheckinRequest $request): JsonResponse
    {
        $employee = Employee::where('fan_number', $request->fan_number)->firstOrFail();

        if ($employee->is_banned) {
            return response()->json([
                'message' => 'This employee is currently banned until ' . $employee->banned_until->toDateString() . '.',
            ], 403);
        }

        $existing = Checkin::where('employee_id', $employee->id)
            ->whereDate('checked_in_at', now()->toDateString())
            ->first();
        if ($existing) {
            return response()->json([
                'message' => 'This employee has already used their daily access.',
                'checkin' => new CheckinResource($existing->load(['employee.user', 'gym'])),
            ], 409);
        }

        $tier     = self::employeeTier($employee);
        $allowed  = self::allowedTiers($tier);
        $gym      = Gym::find($request->gym_id);

        if (!$gym || !in_array($gym->tier ?? 'basic', $allowed)) {
            return response()->json(['message' => 'This gym is not included in this employee\'s plan.'], 422);
        }

        $membership = $this->findOrCreateMembership($employee, $gym, $tier);

        $checkin = Checkin::create([
            'membership_id' => $membership->id,
            'gym_id'        => $request->gym_id,
            'employee_id'   => $employee->id,
            'checked_in_at' => now(),
            'method'        => $request->method ?? 'fan_number',
            'recorded_by'   => $request->recorded_by,
        ]);

        $checkin->load(['employee.user', 'gym']);
        AuditLog::record('created', $checkin);
        return response()->json(new CheckinResource($checkin), 201);
    }

    // ── Employee: list eligible gyms (tier-based) ─────────────────

    public function myGyms(): JsonResponse
    {
        $employee = Employee::where('user_id', auth()->id())->firstOrFail();

        $tier    = self::employeeTier($employee);
        $allowed = self::allowedTiers($tier);

        $gyms = Gym::whereIn('tier', $allowed)
            ->where('is_active', true)
            ->get()
            ->map(fn($g) => [
                'id'       => $g->id,
                'name'     => $g->name,
                'address'  => $g->address  ?? null,
                'city'     => $g->city      ?? null,
                'sub_city' => $g->sub_city  ?? null,
                'tier'     => $g->tier      ?? 'basic',
            ])
            ->values();

        return response()->json(['gyms' => $gyms]);
    }

    // ── Employee: select a gym for today ─────────────────────────

    public function selectGym(Request $request): JsonResponse
    {
        $request->validate(['gym_id' => 'required|exists:gyms,id']);

        $employee = Employee::where('user_id', auth()->id())->firstOrFail();

        $tier    = self::employeeTier($employee);
        $allowed = self::allowedTiers($tier);

        // Verify gym tier is within employee's plan
        $gym = Gym::where('id', $request->gym_id)
            ->whereIn('tier', $allowed)
            ->where('is_active', true)
            ->first();

        if (!$gym) {
            return response()->json([
                'message' => 'This gym is not available under your current plan.',
            ], 422);
        }

        // Already selected today?
        $existing = DailyGymSelection::where('employee_id', $employee->id)
            ->where('selection_date', now()->toDateString())
            ->with('gym')
            ->first();

        if ($existing) {
            return response()->json([
                'message'   => 'You have already selected a gym for today.',
                'selection' => $this->selectionPayload($existing, $employee),
            ], 409);
        }

        // Find or auto-create membership for this gym
        $membership = $this->findOrCreateMembership($employee, $gym, $tier);

        if ($membership->status === 'suspended') {
            return response()->json([
                'message' => 'Your membership at this gym is currently suspended.',
            ], 422);
        }

        $selection = DailyGymSelection::create([
            'employee_id'    => $employee->id,
            'gym_id'         => $request->gym_id,
            'selection_date' => now()->toDateString(),
        ]);
        $selection->load('gym');

        return response()->json([
            'message'   => 'Gym selected. Your barcode is ready.',
            'selection' => $this->selectionPayload($selection, $employee),
        ], 201);
    }

    // ── Employee: rotating barcode token ─────────────────────────

    public function token(): JsonResponse
    {
        $employee = Employee::where('user_id', auth()->id())->firstOrFail();

        $tier    = self::employeeTier($employee);
        $allowed = self::allowedTiers($tier);

        $selection = DailyGymSelection::todayFor($employee->id);

        // All gyms available under this employee's plan
        $gyms = Gym::whereIn('tier', $allowed)
            ->where('is_active', true)
            ->get()
            ->map(fn($g) => [
                'id'       => $g->id,
                'name'     => $g->name,
                'address'  => $g->address  ?? null,
                'city'     => $g->city      ?? null,
                'sub_city' => $g->sub_city  ?? null,
                'tier'     => $g->tier      ?? 'basic',
            ])
            ->values();

        if (!$selection) {
            return response()->json([
                'needs_selection' => true,
                'gyms'            => $gyms,
            ]);
        }

        $slotSeconds = 12 * 3600;
        $now         = time();
        $slot        = intdiv($now, $slotSeconds);
        $expiresAt   = ($slot + 1) * $slotSeconds;

        $token = self::buildToken($employee->id, $selection->gym_id, $slot);

        $todayCheckin = Checkin::where('employee_id', $employee->id)
            ->whereDate('checked_in_at', now()->toDateString())
            ->first();

        return response()->json([
            'needs_selection'          => false,
            'fan_number'               => $employee->fan_number,
            'token'                    => $token,
            'expires_at'               => date('c', $expiresAt),
            'expires_in_seconds'       => $expiresAt - $now,
            'rotates_every_hours'      => 12,
            'already_checked_in_today' => $todayCheckin !== null,
            'checked_in_at'            => $todayCheckin?->checked_in_at?->format('H:i'),
            'selected_gym'             => [
                'id'      => $selection->gym->id,
                'name'    => $selection->gym->name,
                'address' => $selection->gym->address ?? null,
                'city'    => $selection->gym->city    ?? null,
            ],
            'gyms' => $gyms,
        ]);
    }

    // ── Partner: employees who selected this gym today ────────────

    public function expectedVisitors(Request $request): JsonResponse
    {
        $gymId = $request->gym_id;

        if (!$gymId) {
            $user  = auth()->user();
            $gym   = $user->gym_id
                ? Gym::find($user->gym_id)
                : Gym::where('contact_email', $user->email)->first();
            $gymId = $gym?->id;
        }

        if (!$gymId) {
            return response()->json(['visitors' => []]);
        }

        $selections = DailyGymSelection::where('gym_id', $gymId)
            ->where('selection_date', now()->toDateString())
            ->with(['employee.user'])
            ->get();

        $checkedInIds = Checkin::where('gym_id', $gymId)
            ->whereDate('checked_in_at', now()->toDateString())
            ->pluck('employee_id')
            ->toArray();

        $visitors = $selections->map(fn($s) => [
            'employee_id' => $s->employee_id,
            'name'        => $s->employee?->user?->name ?? 'Unknown',
            'fan_number'  => $s->employee?->fan_number,
            'checked_in'  => in_array($s->employee_id, $checkedInIds),
            'selected_at' => $s->created_at->format('H:i'),
        ])->values();

        return response()->json(['visitors' => $visitors]);
    }

    // ── Partner: scan barcode ─────────────────────────────────────

    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'       => 'required|string',
            'gym_id'      => 'required|exists:gyms,id',
            'recorded_by' => 'nullable|string|max:255',
        ]);

        $slotSeconds = 12 * 3600;
        $currentSlot = intdiv(time(), $slotSeconds);

        $employee = null;
        foreach (Employee::query()->select(['id', 'fan_number'])->get() as $e) {
            foreach ([$currentSlot, $currentSlot - 1] as $slot) {
                if (hash_equals(self::buildToken($e->id, (int) $data['gym_id'], $slot), $data['token'])) {
                    $employee = Employee::find($e->id);
                    break 2;
                }
            }
        }

        if (!$employee) {
            return response()->json(['message' => 'Invalid or expired barcode.'], 422);
        }

        // Verify employee selected THIS gym today
        $selection = DailyGymSelection::where('employee_id', $employee->id)
            ->where('gym_id', $data['gym_id'])
            ->where('selection_date', now()->toDateString())
            ->first();

        if (!$selection) {
            return response()->json([
                'message' => 'This employee has not selected this gym for today.',
            ], 422);
        }

        if ($employee->is_banned) {
            return response()->json([
                'message' => 'This employee is currently banned until ' . $employee->banned_until->toDateString() . '.',
            ], 403);
        }

        // Daily access already used?
        $existing = Checkin::where('employee_id', $employee->id)
            ->whereDate('checked_in_at', now()->toDateString())
            ->first();
        if ($existing) {
            return response()->json([
                'message' => 'This employee has already used their daily access today.',
                'checkin' => new CheckinResource($existing->load(['employee.user', 'gym'])),
            ], 409);
        }

        // Verify tier allows this gym
        $tier    = self::employeeTier($employee);
        $allowed = self::allowedTiers($tier);
        $gym     = Gym::find($data['gym_id']);

        if (!$gym || !in_array($gym->tier ?? 'basic', $allowed)) {
            return response()->json(['message' => 'This gym is not included in this employee\'s plan.'], 422);
        }

        // Find or auto-create membership
        $membership = $this->findOrCreateMembership($employee, $gym, $tier);

        if ($membership->status === 'suspended') {
            return response()->json(['message' => 'This employee\'s membership is suspended.'], 422);
        }

        $checkin = Checkin::create([
            'membership_id' => $membership->id,
            'gym_id'        => $data['gym_id'],
            'employee_id'   => $employee->id,
            'checked_in_at' => now(),
            'method'        => 'card',
            'recorded_by'   => $data['recorded_by'] ?? null,
        ]);

        $checkin->load(['employee.user', 'gym']);
        AuditLog::record('created', $checkin);
        return response()->json(new CheckinResource($checkin), 201);
    }

    public function checkout(Checkin $checkin): JsonResponse
    {
        if ($checkin->checked_out_at) {
            return response()->json(['message' => 'Already checked out.'], 422);
        }
        $checkin->update(['checked_out_at' => now()]);
        return response()->json(new CheckinResource($checkin->load(['employee.user', 'gym'])));
    }

    public function show(Checkin $checkin): CheckinResource
    {
        return new CheckinResource($checkin->load(['employee.user', 'gym', 'membership.plan']));
    }

    // ── Tier helpers ──────────────────────────────────────────────

    private static function employeeTier(Employee $e): string
    {
        return match($e->level) {
            'chief'    => 'platinum',
            'director' => 'basic_plus',
            default    => 'basic',
        };
    }

    private static function allowedTiers(string $tier): array
    {
        return match($tier) {
            'platinum'   => ['basic', 'basic_plus', 'platinum'],
            'basic_plus' => ['basic', 'basic_plus'],
            default      => ['basic'],
        };
    }

    // ── Find or auto-create membership ────────────────────────────

    private function findOrCreateMembership(Employee $employee, Gym $gym, string $tier): Membership
    {
        $plan = MembershipPlan::where('tier', $tier)
            ->where('is_active', true)
            ->first();

        /** @var Membership $membership */
        $membership = Membership::firstOrCreate(
            ['employee_id' => $employee->id, 'gym_id' => $gym->id],
            [
                'plan_id'    => $plan?->id,
                'status'     => 'active',
                'start_date' => now()->toDateString(),
                'end_date'   => now()->addYear()->toDateString(),
            ]
        );

        // If it was just created, bump the gym member count
        if ($membership->wasRecentlyCreated) {
            $gym->increment('current_members');
        }

        return $membership;
    }

    // ── Token builder (gym-scoped) ────────────────────────────────

    private static function buildToken(int $employeeId, int $gymId, int $slot): string
    {
        $secret = config('app.key');
        $raw    = hash_hmac('sha256', "emp:{$employeeId}:gym:{$gymId}:slot:{$slot}", $secret);
        return 'FA' . strtoupper(substr($raw, 0, 14));
    }

    private function selectionPayload(DailyGymSelection $sel, Employee $employee): array
    {
        $slotSeconds = 12 * 3600;
        $now         = time();
        $slot        = intdiv($now, $slotSeconds);
        $expiresAt   = ($slot + 1) * $slotSeconds;

        return [
            'fan_number'          => $employee->fan_number,
            'token'               => self::buildToken($employee->id, $sel->gym_id, $slot),
            'expires_at'          => date('c', $expiresAt),
            'expires_in_seconds'  => $expiresAt - $now,
            'rotates_every_hours' => 12,
            'selected_gym'        => [
                'id'      => $sel->gym->id,
                'name'    => $sel->gym->name,
                'address' => $sel->gym->address ?? null,
                'city'    => $sel->gym->city    ?? null,
            ],
        ];
    }
}
