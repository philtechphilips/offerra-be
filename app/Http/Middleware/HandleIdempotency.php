<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class HandleIdempotency
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only idempotent for POST, PUT, PATCH
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH'])) {
            return $next($request);
        }

        $idempotencyKey = $request->header('X-Idempotency-Key');

        if (!$idempotencyKey) {
            return $next($request);
        }

        // Create a unique cache key based on the user (if authenticated) and the header
        $user = $request->user();
        $cacheKey = 'idempotency:' . ($user ? $user->id : 'guest') . ':' . $idempotencyKey;

        // 1. Check if we already have a cached response
        if ($cachedResponse = Cache::get($cacheKey)) {
            Log::info('Idempotency: Returning cached response for key ' . $idempotencyKey);
            return response()->json(
                $cachedResponse['content'],
                $cachedResponse['status'],
                array_merge($cachedResponse['headers'], ['X-Idempotency-Cached' => 'true'])
            );
        }

        // 2. Atomic Lock to prevent race conditions (same key sent twice simultaneously)
        $lockKey = $cacheKey . ':lock';
        $lock = Cache::lock($lockKey, 10); // 10 seconds timeout

        if (!$lock->get()) {
            return response()->json([
                'message' => 'Request already in progress. Please wait.'
            ], 429);
        }

        try {
            // Re-check cache after getting lock (another request might have finished)
            if ($cachedResponse = Cache::get($cacheKey)) {
                $lock->release();
                return response()->json(
                    $cachedResponse['content'],
                    $cachedResponse['status'],
                    array_merge($cachedResponse['headers'], ['X-Idempotency-Cached' => 'true'])
                );
            }

            // 3. Process the request
            $response = $next($request);

            // 4. Cache successful responses only (or optionally 4xx/5xx depending on use case)
            // Usually we only cache successful results (2xx) or client errors (4xx) that are deterministic.
            if ($response->isSuccessful() || ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500)) {
                $dataToCache = [
                    'content' => json_decode($response->getContent(), true),
                    'status' => $response->getStatusCode(),
                    'headers' => collect($response->headers->all())->map(fn($v) => $v[0])->toArray(),
                ];

                // Cache for 24 hours (86400 seconds)
                Cache::put($cacheKey, $dataToCache, 86400);
            }

            return $response;

        } finally {
            $lock->release();
        }
    }
}
