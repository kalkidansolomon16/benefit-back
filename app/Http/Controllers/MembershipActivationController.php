<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\QrToken;
use App\Models\User;
use App\Services\OneTimeCodeService;
use App\Services\SessionTokenService;
use App\Support\CardNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The app is usable only once the 12-digit membership number (emailed when a
 * company pays for an employee) is entered on Home. Stored on the employee
 * (card_activated_at), so it's asked once per employee.
 *
 * Anyone can sign up, so the number may belong to an account HR created
 * with the company email. Moving it to this account needs a 6-digit code
 * sent to that company email: the number alone isn't secret (front-desk
 * staff see it; it's printed on the card).
 */
class MembershipActivationController extends Controller
{
    private const MAX_WRONG = 5;
    private const LOCK_MINUTES = 15;

    public function __construct(private readonly OneTimeCodeService $codes) {}

    /** POST /mobile/membership/activate {member_number} */
    public function activate(Request $request): JsonResponse
    {
        $request->validate(['member_number' => 'required|string|max:20']);
        $me = $request->user();
        if ($locked = $this->tooMany($me)) {
            return $locked;
        }

        $number = CardNumber::normalize($request->input('member_number'));
        $employee = $number ? Employee::with('user', 'company')->where('card_number', $number)->first() : null;

        // No such number, or this account already has a different membership.
        if (!$employee || ($me->employee && (int) $me->employee->id !== (int) $employee->id)) {
            return $this->wrong($me);
        }

        // Their own membership: activate.
        if ((int) $employee->user_id === (int) $me->id) {
            Cache::forget($this->failKey($me));

            return $this->activated($employee);
        }

        // Someone else's: only an account HR created that nobody has used.
        $holder = $employee->user;
        if (!$holder || !$holder->must_reset_password) {
            return response()->json([
                'message' => 'This membership is already used on another account. Contact your HR so they can confirm it’s yours.',
                'code' => 'already_linked',
            ], 409);
        }

        $this->codes->send('activate', $this->subject($me, $employee), 'email', $holder->email, $holder->name, 'adding your company membership to the FitAccess app');

        return response()->json([
            'code' => 'confirm_by_email',
            'message' => 'This number belongs to the email your company registered. We sent a 6-digit code there to confirm it’s you.',
            'data' => ['masked_contact' => OneTimeCodeService::mask($holder->email), 'expires_in' => OneTimeCodeService::TTL_MINUTES * 60],
        ], 202);
    }

    /** POST /mobile/membership/activate/confirm {member_number, code} */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['member_number' => 'required|string|max:20', 'code' => 'required|digits:6']);
        $me = $request->user();
        $number = CardNumber::normalize($request->input('member_number'));
        $employee = $number ? Employee::with('user', 'company')->where('card_number', $number)->first() : null;
        $holder = $employee?->user;

        if (!$employee || !$holder || !$holder->must_reset_password || (int) $holder->id === (int) $me->id || $me->employee) {
            return response()->json(['message' => 'This membership can’t be added. Contact your HR.', 'code' => 'already_linked'], 409);
        }

        $result = $this->codes->verify('activate', $this->subject($me, $employee), $request->input('code'));
        if ($result !== 'ok') {
            return response()->json([
                'message' => $result === 'wrong' ? 'That code isn’t right. Check the email and try again.' : 'This code has expired. Enter the number again to get a new one.',
                'code' => $result === 'wrong' ? 'wrong_code' : 'code_expired',
            ], 422);
        }

        DB::transaction(function () use ($me, $employee, $holder) {
            $employee->forceFill(['user_id' => $me->id])->save();
            $me->forceFill([
                'role' => $me->role === 'member' ? 'employee' : $me->role,
                'company_id' => $employee->company_id,
                'company_branch_id' => $employee->company_branch_id,
            ])->save();
            // The account HR created was never used; switch it off.
            $holder->forceFill(['is_active' => false])->save();
            app(SessionTokenService::class)->revokeAll($holder);
            QrToken::where('user_id', $holder->id)->delete();
        });
        AuditLog::record('membership_linked', $employee, ['user_id' => $holder->id], ['user_id' => $me->id]);
        Log::info('membership.activate.linked', ['user_id' => $me->id, 'employee_id' => $employee->id, 'old_user_id' => $holder->id]);
        Cache::forget($this->failKey($me));

        return $this->activated($employee->fresh(['company']));
    }

    /** POST /mobile/membership/activate/resend {member_number} */
    public function resend(Request $request): JsonResponse
    {
        $request->validate(['member_number' => 'required|string|max:20']);
        $me = $request->user();
        $number = CardNumber::normalize($request->input('member_number'));
        $employee = $number ? Employee::with('user')->where('card_number', $number)->first() : null;
        $holder = $employee?->user;
        if (!$holder || !$holder->must_reset_password || $me->employee) {
            return response()->json(['message' => 'This membership can’t be added. Contact your HR.', 'code' => 'already_linked'], 409);
        }
        $this->codes->send('activate', $this->subject($me, $employee), 'email', $holder->email, $holder->name, 'adding your company membership to the FitAccess app');

        return response()->json(['data' => ['masked_contact' => OneTimeCodeService::mask($holder->email)]]);
    }

    private function activated(Employee $employee): JsonResponse
    {
        if (!$employee->card_activated_at) {
            $employee->forceFill(['card_activated_at' => now()])->save();
            AuditLog::record('card_activated', $employee);
        }

        return response()->json(['data' => [
            'member_number' => $employee->card_number,
            'company_name' => $employee->company?->name,
            'card_activated' => true,
        ]]);
    }

    private function wrong(User $me): JsonResponse
    {
        Cache::add($this->failKey($me), 0, now()->addMinutes(self::LOCK_MINUTES));
        Cache::increment($this->failKey($me));
        Log::warning('membership.activate.wrong', ['user_id' => $me->id]);

        return response()->json(['message' => 'That isn’t a valid membership number for this account. Check the email from FitAccess and try again.', 'code' => 'wrong_number'], 422);
    }

    private function tooMany(User $me): ?JsonResponse
    {
        if ((int) Cache::get($this->failKey($me), 0) < self::MAX_WRONG) {
            return null;
        }

        return response()->json([
            'message' => 'Too many wrong numbers. Try again in 15 minutes, or ask your HR for your membership number.',
            'code' => 'too_many',
            'retry_after' => self::LOCK_MINUTES * 60,
        ], 429);
    }

    private function failKey(User $me): string
    {
        return "membership-activate-fail:{$me->id}";
    }

    /** The code is tied to this account and this membership. */
    private function subject(User $me, Employee $employee): string
    {
        return "user:{$me->id}|employee:{$employee->id}";
    }
}
