<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a write safe to retry: a request carrying an `Idempotency-Key` header runs
 * once, and repeating it with the same key and body replays the stored response
 * (with `Idempotent-Replayed: true`) instead of doing the work again. Keys are scoped
 * to the caller and the route, and kept for 24 hours. Requests without the header
 * are untouched.
 */
class Idempotent
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAYED_HEADER = 'Idempotent-Replayed';

    private const TTL_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);

        if ($key === null) {
            return $next($request);
        }

        if ($key === '' || strlen($key) > 255) {
            return $this->invalidKey('The Idempotency-Key header must be between 1 and 255 characters.');
        }

        $cacheKey = self::cacheKey($request->user()?->getAuthIdentifier() ?? $request->ip(), $request->method(), $request->path(), $key);
        $fingerprint = hash('sha256', $request->getContent());

        // Held while the first request runs, so a concurrent duplicate can't slip past the cache check.
        $lock = Cache::lock("{$cacheKey}:lock", 30);

        if (! $lock->get()) {
            return Problem::make(409, 'Request in progress', 'A request with this Idempotency-Key is still being processed. Retry shortly.', 'idempotency_in_progress');
        }

        try {
            $stored = Cache::get($cacheKey);

            if ($stored !== null) {
                return $stored['fingerprint'] === $fingerprint
                    ? $this->replay($stored)
                    : $this->invalidKey('This Idempotency-Key was already used with a different request body.', 'idempotency_key_reused');
            }

            $response = $next($request);

            // Server errors aren't stored, so a retry after one gets a real second attempt.
            if ($response->getStatusCode() < 500) {
                Cache::put($cacheKey, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'content' => $response->getContent(),
                    'headers' => array_filter([
                        'Content-Type' => $response->headers->get('Content-Type'),
                        'Location' => $response->headers->get('Location'),
                    ]),
                ], now()->addHours(self::TTL_HOURS));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    /**
     * The cache key for one caller's use of one key on one route.
     */
    public static function cacheKey(int|string|null $caller, string $method, string $path, string $key): string
    {
        return 'idempotency:'.hash('sha256', implode("\0", [$caller, $method, $path, $key]));
    }

    /**
     * @param  array{status: int, content: string, headers: array<string, string>}  $stored
     */
    private function replay(array $stored): Response
    {
        return new Response($stored['content'], $stored['status'], [...$stored['headers'], self::REPLAYED_HEADER => 'true']);
    }

    private function invalidKey(string $message, string $code = 'validation_failed'): Response
    {
        return Problem::make(422, 'Invalid Idempotency-Key', $message, $code, [
            'errors' => [self::HEADER => [$message]],
        ]);
    }
}
