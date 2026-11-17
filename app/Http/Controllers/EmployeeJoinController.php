<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Rules\StrongPassword;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Public: an employee imported by their company creates their FitAccess
 * account. They confirm first name, last name, birth date and email exactly as
 * the company listed them, and choose a password.
 *
 * The answer never says which detail was wrong, so the form cannot be used to
 * find out who is registered.
 */
class EmployeeJoinController extends Controller
{
    private const NO_MATCH = 'These details do not match the list your company sent to FitAccess. Check your name, birth date and email, or contact your HR team.';

    /** What the invitation link is for (company name, masked email) */
    public function lookup(string $token): JsonResponse
    {
        $employee = $this->byToken($token);
        if (!$employee) {
            return response()->json(['valid' => false, 'message' => 'This invitation link is not valid. Ask your HR team to send a new one.'], 404);
        }
        if ($employee->account_created_at) {
            return response()->json(['valid' => false, 'already' => true, 'message' => 'You have already created your account. Please sign in.']);
        }
        if ($employee->invite_expires_at && $employee->invite_expires_at->isPast()) {
            return response()->json(['valid' => false, 'expired' => true, 'message' => 'This invitation link has expired. Ask your HR team to send a new one.']);
        }

        return response()->json([
            'valid'   => true,
            'company' => $employee->company?->name,
            'email'   => $employee->user?->email,
        ]);
    }

    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'      => 'nullable|string|max:64',
            'email'      => 'required|email|max:255',
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'birth_date' => 'required|date_format:Y-m-d',
            'password'   => ['required', 'string', 'max:100', 'confirmed', new StrongPassword()],
        ]);

        $email = strtolower(trim($data['email']));
        $lockKey = 'join_fail:' . sha1($email . '|' . $request->ip());
        if ((int) Cache::get($lockKey, 0) >= 5) {
            return response()->json(['message' => 'Too many attempts. Please wait 15 minutes or contact your HR team.'], 429);
        }

        $employee = !empty($data['token'])
            ? $this->byToken($data['token'])
            : Employee::with('user', 'company')
                ->whereNull('account_created_at')
                ->whereHas('user', fn ($q) => $q->where('email', $email))
                ->first();

        if ($employee && $employee->account_created_at) {
            return response()->json(['message' => 'You have already created your account. Please sign in.', 'already' => true], 422);
        }

        $ok = $employee
            && $employee->user
            && strtolower($employee->user->email) === $email
            && $employee->birth_date?->toDateString() === $data['birth_date']
            && self::nameMatches((string) $employee->user->name, $data['first_name'], $data['last_name']);

        if (!$ok) {
            Cache::put($lockKey, (int) Cache::get($lockKey, 0) + 1, now()->addMinutes(15));

            return response()->json(['message' => self::NO_MATCH, 'errors' => ['email' => [self::NO_MATCH]]], 422);
        }

        if ($employee->invite_expires_at && $employee->invite_expires_at->isPast()) {
            return response()->json(['message' => 'Your invitation has expired. Ask your HR team to send a new one.'], 422);
        }

        Cache::forget($lockKey);
        $employee->user->update([
            'password'            => Hash::make($data['password']),
            'must_reset_password' => false,
        ]);
        $employee->update([
            'account_created_at' => now(),
            'invite_token_hash'  => null,
        ]);
        AuditLog::record('updated', $employee, [], ['account_created' => true]);

        Notifier::user($employee->user, 'account_created', 'Welcome to FitAccess',
            'Your account is ready. You can explore gyms, trainings and news now. Your card number will be sent to you after your company completes the payment.',
            ['buttonText' => 'Sign in', 'buttonUrl' => config('app.frontend_url') . '/login']);

        return response()->json([
            'message' => 'Your account has been created. You can sign in now.',
            'email'   => $employee->user->email,
        ]);
    }

    private function byToken(string $token): ?Employee
    {
        if (strlen($token) < 20) return null;

        return Employee::with('user', 'company')->where('invite_token_hash', hash('sha256', $token))->first();
    }

    /** "Abebe Girma Haile" matches first "abebe" + last "haile" */
    public static function nameMatches(string $full, string $first, string $last): bool
    {
        $norm = fn ($s) => preg_replace('/\s+/', ' ', mb_strtolower(trim($s)));
        $full = $norm($full);

        return $full !== ''
            && str_starts_with($full, $norm($first) . ' ')
            && str_ends_with($full, ' ' . $norm($last));
    }
}
