<?php

namespace App\Http\Controllers;

use App\Support\CardNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * First sign-in on a phone: after the password and the email code, the
 * employee enters the 12-digit membership number their company's package
 * emailed them. The app asks once per phone.
 */
class MembershipActivationController extends Controller
{
    private const MAX_WRONG = 5;
    private const LOCK_MINUTES = 15;

    /** POST /mobile/membership/activate {member_number} */
    public function activate(Request $request): JsonResponse
    {
        $request->validate(['member_number' => 'required|string|max:20']);
        $user = $request->user();
        $key = "membership-activate-fail:{$user->id}";

        if ((int) Cache::get($key, 0) >= self::MAX_WRONG) {
            return response()->json([
                'message' => 'Too many wrong numbers. Try again in 15 minutes, or ask your HR for your membership number.',
                'code' => 'too_many',
                'retry_after' => self::LOCK_MINUTES * 60,
            ], 429);
        }

        $mine = $user->employee?->card_number;
        if (!$mine) {
            return response()->json(['message' => 'There’s no company membership on this account yet. Ask your HR.', 'code' => 'no_membership'], 422);
        }

        $entered = CardNumber::normalize($request->input('member_number'));
        if ($entered === null || !hash_equals($mine, $entered)) {
            Cache::add($key, 0, now()->addMinutes(self::LOCK_MINUTES));
            Cache::increment($key);
            Log::warning('membership.activate.wrong', ['user_id' => $user->id]);

            return response()->json(['message' => 'That isn’t your membership number. Check the email from FitAccess and try again.', 'code' => 'wrong_number'], 422);
        }

        Cache::forget($key);

        return response()->json(['data' => ['member_number' => $mine, 'company_name' => $user->employee->company?->name]]);
    }
}
