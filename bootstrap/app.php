<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'idempotent' => \App\Http\Middleware\HandleIdempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (\Throwable $e) {
            logger()->error('Unhandled exception', [
                'type' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException) {
                $errors = $e->errors();
                $firstMessage = null;
                foreach ($errors as $fieldErrors) {
                    if (is_array($fieldErrors) && !empty($fieldErrors)) {
                        $firstMessage = (string) $fieldErrors[0];
                        break;
                    }
                }

                return response()->json([
                    'message' => $firstMessage ?: ($e->getMessage() ?: 'Validation failed.'),
                    'error_code' => 'VALIDATION_ERROR',
                    'errors' => $errors,
                ], 422);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'error_code' => 'UNAUTHENTICATED',
                ], 401);
            }

            if ($e instanceof AuthorizationException) {
                return response()->json([
                    'message' => 'Forbidden.',
                    'error_code' => 'FORBIDDEN',
                ], 403);
            }

            if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
                return response()->json([
                    'message' => 'Resource not found.',
                    'error_code' => 'NOT_FOUND',
                ], 404);
            }

            if ($e instanceof \Illuminate\Http\Exceptions\ThrottleRequestsException) {
                $headers = $e->getHeaders();
                return response()->json([
                    'message' => 'Too many requests. Please slow down and try again shortly.',
                    'error_code' => 'RATE_LIMITED',
                    'retry_after' => isset($headers['Retry-After']) ? (int) $headers['Retry-After'] : null,
                ], 429, $headers);
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            if ($status >= 500) {
                return response()->json([
                    'message' => 'Something went wrong.',
                    'error_code' => 'SERVER_ERROR',
                ], $status);
            }

            return response()->json([
                'message' => $e->getMessage() ?: 'Request failed.',
                'error_code' => 'REQUEST_ERROR',
            ], $status);
        });
    })->create();
