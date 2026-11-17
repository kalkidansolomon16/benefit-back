<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Employee portal actions need an activated card: the employee must first
 * enter the card number HR sent them. Without it the portal is view-only.
 */
class EnsureCardActivated
{
    public function handle(Request $request, Closure $next): Response
    {
        $employee = Employee::where('user_id', $request->user()?->id)->first();

        if (!$employee) {
            return response()->json(['message' => 'No employee record linked to this account.'], 403);
        }

        if ($employee->is_banned) {
            return response()->json([
                'message' => 'Your card is suspended ' . $employee->banUntilText() . '. Please contact your HR team.',
                'code'    => 'card_banned',
            ], 403);
        }

        if (!$employee->hasActivatedCard() || !$request->user()->is_active) {
            return response()->json([
                'message' => 'Activate your FitAccess card first: enter the card number your company sent you.',
                'code'    => 'card_not_activated',
            ], 403);
        }

        return $next($request);
    }
}
