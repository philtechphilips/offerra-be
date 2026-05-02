<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Wraps controller actions in a consistent try/catch that:
 *  - lets framework-level exceptions (validation, auth, HTTP, 404) bubble so the
 *    global handler in bootstrap/app.php formats them correctly,
 *  - logs all other unexpected errors with controller context,
 *  - returns a generic, user-safe "Something went wrong." 500 response.
 */
trait HandlesApiErrors
{
    /**
     * Execute a controller action safely.
     *
     * @template T
     *
     * @param callable(): T $fn
     * @param string $context Short label used in logs (e.g. "JobApplicationController@store").
     * @return T|\Illuminate\Http\JsonResponse
     */
    protected function safeCall(callable $fn, string $context = '')
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            if (
                $e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof AuthorizationException
                || $e instanceof ModelNotFoundException
                || $e instanceof HttpResponseException
                || $e instanceof HttpExceptionInterface
            ) {
                throw $e;
            }

            Log::error('Controller error', [
                'context' => $context !== '' ? $context : static::class,
                'type' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Something went wrong.',
                'error_code' => 'SERVER_ERROR',
            ], 500);
        }
    }

    /**
     * Build the standard "Something went wrong." JSON response. Useful when callers
     * already have a custom catch block they want to preserve and just need a safe
     * payload to return to the user.
     */
    protected function genericServerErrorResponse(int $status = 500)
    {
        return response()->json([
            'message' => 'Something went wrong.',
            'error_code' => 'SERVER_ERROR',
        ], $status);
    }
}
