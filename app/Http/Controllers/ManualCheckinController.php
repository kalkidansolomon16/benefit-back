<?php

namespace App\Http\Controllers;

use App\Models\AdminNotification;
use App\Models\AuditLog;
use App\Models\Checkin;
use App\Models\Employee;
use App\Models\Gym;
use App\Models\GymStaff;
use App\Models\MemberVerification;
use App\Models\Notification;
use App\Models\User;
use App\Services\CheckinService;
use App\Services\VerificationService;
use App\Support\CardNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * v2 manual check-in at the front desk, for members who can't show a
 * code: staff type the 12-digit member number, compare the member's
 * verification photo with a national ID, then confirm.
 *
 * The number alone never checks anyone in. Manual check-ins are limited
 * per member per month, recorded against the staff account, and the
 * member is notified each time so they can report misuse.
 */
class ManualCheckinController extends Controller
{
    public function __construct(
        private readonly CheckinService $checkins,
        private readonly VerificationService $verifications,
    ) {}

    /** GET /partner/members/{number} */
    public function lookup(Request $request, string $number): JsonResponse
    {
        [$gym, $error] = $this->staffGym($request);
        if ($error) {
            return $error;
        }

        $member = $this->findMember($number);
        Log::info('partner.manual.lookup', ['user_id' => $request->user()->id, 'gym_id' => $gym->id, 'found' => (bool) $member]);
        if (!$member) {
            return response()->json(['message' => 'No member with this number. Check the 12 digits with the member.'], 404);
        }

        return response()->json(['data' => $this->summary($member, $gym)]);
    }

    /** GET /partner/members/{number}/photo — the photo staff compare with the ID. */
    public function photo(Request $request, string $number): StreamedResponse|JsonResponse
    {
        [, $error] = $this->staffGym($request);
        if ($error) {
            return $error;
        }

        $member = $this->findMember($number);
        $v = $member ? $this->verifications->photoFor($member) : null;
        $disk = Storage::disk(VerificationService::DISK);
        if (!$v || !$disk->exists($v->photo_path)) {
            return response()->json(['message' => 'No photo.'], 404);
        }

        return $disk->response($v->photo_path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** POST /partner/manual-checkins {member_number, id_checked: true} */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'member_number' => 'required|string|max:20',
            'id_checked' => 'required|accepted',
        ]);
        [$gym, $error] = $this->staffGym($request);
        if ($error) {
            return $error;
        }

        $member = $this->findMember($request->member_number);
        if (!$member) {
            return response()->json(['message' => 'No member with this number.'], 404);
        }

        $summary = $this->summary($member, $gym);
        if ($summary['access'] !== 'ok') {
            return response()->json(['message' => $summary['message'], 'access' => $summary['access']], 422);
        }

        $eligible = $this->checkins->eligibility($member, $gym);
        $limit = (int) config('fitaccess.manual_checkins_per_month', 2);
        $outcome = $this->checkins->record(
            $gym, $member, $eligible, 'manual', $request->user()->name,
            // Re-checked inside the lock so two desks can't both use the last one.
            fn () => $this->manualUsedThisMonth($member) >= $limit ? ['manual_limit', 'Monthly limit for manual check-ins reached.'] : null,
        );

        if ($outcome[0] === 'rejected') {
            return response()->json(['message' => $outcome[2], 'access' => $outcome[1]], 422);
        }
        [, $checkin] = $outcome;

        // Staff just compared the photo with a national ID: that's the
        // first-visit verification.
        $latest = $this->verifications->latest($member);
        if ($latest && $latest->status === MemberVerification::PENDING) {
            $this->verifications->approve($latest, $request->user(), $gym->id);
        }

        Notification::create([
            'user_id' => $member->id,
            'title' => "Your membership was used at {$gym->name}",
            'body' => 'Manual check-in, '.$checkin->checked_in_at->format('H:i').'. Not you? Report it.',
            'type' => 'manual_checkin',
            'data' => ['checkin_id' => $checkin->id, 'gym' => $gym->name, 'at' => $checkin->checked_in_at->toIso8601String()],
        ]);
        AuditLog::record('manual_checkin', $checkin);
        Log::info('partner.manual.recorded', ['user_id' => $request->user()->id, 'gym_id' => $gym->id, 'member_id' => $member->id, 'checkin_id' => $checkin->id]);

        return response()->json(['data' => [
            'member_name' => $member->name,
            'checked_in_at' => $checkin->checked_in_at->toIso8601String(),
            'checked_by' => $request->user()->name,
            'manual_left' => max(0, $limit - $this->manualUsedThisMonth($member)),
        ]], 201);
    }

    /** POST /partner/manual-checkins/mismatch {member_number, reason} */
    public function mismatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'member_number' => 'required|string|max:20',
            'reason' => 'required|in:face,name,no_id',
        ]);
        [$gym, $error] = $this->staffGym($request);
        if ($error) {
            return $error;
        }

        $member = $this->findMember($data['member_number']);
        if (!$member) {
            return response()->json(['message' => 'No member with this number.'], 404);
        }

        $this->pause($member, "Front desk at {$gym->name}: ".match ($data['reason']) {
            'face' => 'face did not match the photo',
            'name' => 'name on ID did not match',
            default => 'no ID shown',
        }, ['gym_id' => $gym->id, 'staff_id' => $request->user()->id, 'reason' => $data['reason']]);

        return response()->json(['message' => 'Logged. Manual check-ins are paused for this member.']);
    }

    /** POST /mobile/manual-checkins/{checkin}/report — "it wasn't me". */
    public function report(Request $request, Checkin $checkin): JsonResponse
    {
        $user = $request->user();
        if ((int) $checkin->user_id !== (int) $user->id || $checkin->method !== 'manual') {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $this->pause($user, "Member reported manual check-in #{$checkin->id} as not them", ['checkin_id' => $checkin->id, 'gym_id' => $checkin->gym_id]);

        return response()->json(['message' => 'Thanks. Manual check-ins are paused on your account and we will contact you.']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0: ?Gym, 1: ?JsonResponse} */
    private function staffGym(Request $request): array
    {
        $staff = $request->user();
        $gym = null;
        if (in_array($staff->role, ['gym_staff', 'gym_partner'], true)) {
            $link = GymStaff::where('user_id', $staff->id)->where('is_active', true)->first();
            $gym = $link ? Gym::find($link->gym_id) : Gym::forPartner($staff, null, Gym::where('is_active', true));
        }
        if (!$gym || !$gym->is_active) {
            return [null, response()->json(['message' => 'Your account is not assigned to an active facility.'], 403)];
        }

        return [$gym, null];
    }

    private function findMember(string $input): ?User
    {
        $number = CardNumber::normalize($input);
        if (!$number) {
            return null;
        }
        $employee = Employee::where('card_number', $number)->whereNotNull('user_id')->first();

        return $employee ? User::find($employee->user_id) : User::where('member_number', $number)->first();
    }

    private function manualUsedThisMonth(User $member): int
    {
        return Checkin::where('user_id', $member->id)
            ->where('method', 'manual')
            ->where('checked_in_at', '>=', now()->startOfMonth())
            ->count();
    }

    /** What reception sees, and whether a manual check-in is allowed. */
    private function summary(User $member, Gym $gym): array
    {
        $limit = (int) config('fitaccess.manual_checkins_per_month', 2);
        $used = $this->manualUsedThisMonth($member);
        $verification = $this->verifications->statusFor($member);
        $eligible = $this->checkins->eligibility($member, $gym);
        $company = $member->employee?->company?->name;

        [$access, $message] = match (true) {
            $member->manual_checkins_paused_at !== null => ['paused', 'Manual check-ins are paused for this member. They need to check in with the code in their app.'],
            !in_array($verification, ['pending', 'verified'], true) => ['not_verified', 'This member hasn’t verified their identity yet, so there’s no photo to compare. They need to check in with the code in their app.'],
            !$eligible['ok'] => [in_array($eligible['code'], ['no_subscription', 'not_paid'], true) ? 'expired' : $eligible['code'], $eligible['message']],
            $used >= $limit => ['limit_reached', "This member has used {$used} of {$limit} manual check-ins this month. They need to check in with the code in their app."],
            default => ['ok', null],
        };

        $planName = $eligible['ok'] ? $eligible['plan_name'] : null;

        return [
            'name' => $member->name,
            'member_number' => $member->memberNumber(),
            'access' => $access,
            'message' => $message,
            'verification_status' => $verification,
            'plan_line' => implode(' · ', array_filter([$planName, $company])) ?: 'No active plan',
            'manual_left' => max(0, $limit - $used),
            'manual_per_month' => $limit,
            'has_photo' => $this->verifications->photoFor($member) !== null,
        ];
    }

    private function pause(User $member, string $why, array $data): void
    {
        $member->forceFill(['manual_checkins_paused_at' => now()])->save();
        AuditLog::record('manual_checkins_paused', $member, [], $data);
        AdminNotification::create([
            'type' => 'manual_checkin_flag',
            'title' => 'Manual check-in flagged — '.$member->name,
            'message' => $why.'. Manual check-ins are paused for this member.',
            'data' => $data + ['member_id' => $member->id],
        ]);
        Log::warning('manual_checkins.paused', $data + ['member_id' => $member->id]);
    }
}
