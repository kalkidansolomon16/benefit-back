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
 * v2 "Got a FitAccess number from your company?": moves a company
 * membership onto the member's own account.
 *
 * HR creates an account for every employee (work email + temporary
 * password). If the employee already uses FitAccess with a personal
 * account, they can link the company membership to it with the 12-digit
 * card number, confirmed by a code sent to the email the company
 * registered. Only accounts nobody has used yet (temporary password never
 * changed) can be linked; anything else goes through HR.
 */
class LinkMembershipController extends Controller
{
    private const MAX_LOOKUPS = 5;
    private const LOCK_MINUTES = 15;

    public function __construct(private readonly OneTimeCodeService $codes) {}

    /** POST /mobile/link/lookup {member_number} */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate(['member_number' => 'required|string|max:20']);
        $me = $request->user();

        if ($locked = $this->tooMany($me)) {
            return $locked;
        }
        $this->countLookup($me);

        $employee = $this->employeeFor($request->member_number);
        if (!$employee) {
            return response()->json(['message' => 'We couldn’t find this number. Check it with your HR and try again.', 'code' => 'not_found'], 404);
        }
        if ((int) $employee->user_id === (int) $me->id) {
            return response()->json(['message' => 'This membership is already on your account.', 'code' => 'already_yours'], 409);
        }

        $holder = User::find($employee->user_id);
        if (!$holder || !$this->unused($holder) || $me->employee) {
            return response()->json([
                'message' => 'This number is already linked to another account. Contact your HR so they can confirm it’s yours and move it to this account.',
                'code' => 'already_linked',
            ], 409);
        }

        $this->codes->send('link', $this->subject($me, $employee), 'email', $holder->email, $holder->name, 'linking your company membership');

        return response()->json(['data' => [
            'masked_contact' => OneTimeCodeService::mask($holder->email),
            'via' => 'email',
            'expires_in' => OneTimeCodeService::TTL_MINUTES * 60,
        ]]);
    }

    /** POST /mobile/link/confirm {member_number, code} */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['member_number' => 'required|string|max:20', 'code' => 'required|digits:6']);
        $me = $request->user();

        $employee = $this->employeeFor($request->member_number);
        $holder = $employee ? User::find($employee->user_id) : null;
        if (!$employee || !$holder || !$this->unused($holder) || $me->employee) {
            return response()->json(['message' => 'This number can’t be linked. Contact your HR.', 'code' => 'already_linked'], 409);
        }

        $result = $this->codes->verify('link', $this->subject($me, $employee), $request->code);
        if ($result !== 'ok') {
            return response()->json([
                'message' => $result === 'wrong' ? 'That code isn’t right. Check the email and try again.' : 'This code has expired. Ask for a new one.',
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

            // The HR-created account was never used; switch it off.
            $holder->forceFill(['is_active' => false])->save();
            app(SessionTokenService::class)->revokeAll($holder);
            QrToken::where('user_id', $holder->id)->delete();
        });

        AuditLog::record('membership_linked', $employee, ['user_id' => $holder->id], ['user_id' => $me->id]);
        Log::info('link.confirmed', ['user_id' => $me->id, 'employee_id' => $employee->id, 'old_user_id' => $holder->id]);
        Cache::forget($this->lookupKey($me));

        return response()->json(['data' => ['company_name' => $employee->company?->name]]);
    }

    /** POST /mobile/link/resend {member_number} */
    public function resend(Request $request): JsonResponse
    {
        return $this->lookup($request);
    }

    private function employeeFor(string $input): ?Employee
    {
        $number = CardNumber::normalize($input);

        return $number ? Employee::with('company')->where('card_number', $number)->first() : null;
    }

    /** Created by HR and never signed in with: temporary password still set. */
    private function unused(User $holder): bool
    {
        return $holder->role === 'employee' && (bool) $holder->must_reset_password;
    }

    private function subject(User $me, Employee $employee): string
    {
        return "user:{$me->id}|employee:{$employee->id}";
    }

    private function lookupKey(User $me): string
    {
        return 'link-lookups:'.$me->id;
    }

    private function countLookup(User $me): void
    {
        Cache::add($this->lookupKey($me), 0, now()->addMinutes(self::LOCK_MINUTES));
        Cache::increment($this->lookupKey($me));
    }

    private function tooMany(User $me): ?JsonResponse
    {
        if ((int) Cache::get($this->lookupKey($me), 0) < self::MAX_LOOKUPS) {
            return null;
        }

        return response()->json([
            'message' => 'For your security, linking is paused on this account. Try again in 15 minutes.',
            'code' => 'too_many',
            'retry_after' => self::LOCK_MINUTES * 60,
        ], 429);
    }
}
