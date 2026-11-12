<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Usage: Route::middleware('permission:billing.view')
     *        Route::middleware('permission:companies.view|billing.view')  (any of)
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $anyOf = explode('|', $permission);

        if (!collect($anyOf)->contains(fn (string $p) => $user->hasPermission($p))) {
            return response()->json([
                'message'    => 'You do not have permission to perform this action.',
                'permission' => $permission,
            ], 403);
        }

        return $next($request);
    }
}
