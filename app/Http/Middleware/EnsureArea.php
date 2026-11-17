<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route group to the "areas" (kinds of account) allowed to use it,
 * e.g. Route::middleware('area:admin') or 'area:member,employee'.
 *
 * Areas come from User::area(): admin, company, gym, employee, member.
 * "fulladmin" additionally means super_admin / fitaccess_admin only.
 *
 * This is the coarse gate (OWASP API5, CWE-285). Fine-grained rules stay in
 * the 'permission:' middleware and in the controllers' own scoping.
 */
class EnsureArea
{
    public function handle(Request $request, Closure $next, string ...$areas): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $allowed = in_array($user->area(), $areas, true)
            || (in_array('fulladmin', $areas, true) && $user->isFullAdmin());

        if (!$allowed) {
            return response()->json(['message' => 'You do not have access to this area.'], 403);
        }

        return $next($request);
    }
}
