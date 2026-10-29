<?php

namespace App\Http\Middleware;

use App\Models\Gym;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gym owners and branch logins can switch between branches (X-Gym-Id header).
 * Reading another branch is allowed, but any change (POST/PUT/PATCH/DELETE)
 * is only allowed on a branch the user may manage:
 *  - the main owner can change every branch
 *  - a branch login can change only its own branch
 */
class EnsureBranchWritable
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $user = $request->user();
        if (!$user || $user->role !== 'gym_partner') {
            return $next($request);
        }

        $gym = Gym::forPartner($user, $request->header('X-Gym-Id'));
        if ($gym && !Gym::canWrite($user, $gym)) {
            return response()->json([
                'message' => 'Read-only: you are viewing another branch. Switch back to your own branch to make changes.',
            ], 403);
        }

        return $next($request);
    }
}
