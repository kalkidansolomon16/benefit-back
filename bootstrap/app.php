<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'branch.write' => \App\Http\Middleware\EnsureBranchWritable::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (! $request->is('api/*') || ! app()->environment('production')) {
                return null;
            }
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return null;
            }

            $status = 500;
            $message = 'Something went wrong. Please try again later.';

            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                $status = 401;
                $message = 'Unauthenticated.';
            } elseif ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                $status = 403;
                $message = 'This action is unauthorized.';
            } elseif ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                   || $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                $status = 404;
                $message = 'Resource not found.';
            } elseif ($e instanceof \Illuminate\Http\Exceptions\ThrottleRequestsException) {
                $status = 429;
                $message = 'Too many requests. Please try again later.';
            } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                // abort(403, '...') etc.: the message was written for the user, so keep it.
                // Server errors (5xx) stay generic so internals never leak.
                $status = $e->getStatusCode();
                if ($status >= 400 && $status < 500) {
                    $message = $e->getMessage() !== ''
                        ? $e->getMessage()
                        : [
                            400 => 'The request could not be processed.',
                            403 => 'You do not have permission to do this.',
                            405 => 'This action is not allowed.',
                            409 => 'This conflicts with the current state. Please refresh and try again.',
                            422 => 'The submitted data is not valid.',
                        ][$status] ?? $message;
                }
            } elseif (method_exists($e, 'getStatusCode')) {
                $status = $e->getStatusCode();
            }

            return response()->json(['message' => $message], $status);
        });
    })->create();
