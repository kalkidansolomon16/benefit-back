<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;

class RejectUntrustedHost
{
    /**
     * Rejects requests whose Host header isn't one of this API's own names
     * (INSA CWE-20, host header injection). Runs before anything else, so a
     * forged Host can never reach code that builds links or sends email.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('fitaccess.enforce_trusted_hosts')) {
            return $next($request);
        }

        try {
            $host = strtolower($request->getHost());
        } catch (SuspiciousOperationException) {
            $host = '';
        }

        $trusted = array_map('strtolower', config('fitaccess.trusted_hosts', []));

        if ($host === '' || ! in_array($host, $trusted, true)) {
            Log::warning('request.untrusted_host', ['host' => Str::limit($host, 100)]);

            return response()->json(['message' => 'Invalid host.'], 400);
        }

        return $next($request);
    }
}
