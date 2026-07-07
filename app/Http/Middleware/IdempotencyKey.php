<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyKey
{
    private const CACHE_TTL_SECONDS = 300;

    private const LOCK_TTL_SECONDS = 30;

    private const LOCK_BLOCK_SECONDS = 5;

    private const MAX_KEY_LENGTH = 255;

    /**
     * Endpoints whose responses must never be replayed via the idempotency
     * cache because they contain tokens, reset links, or 2FA payloads.
     */
    private const SENSITIVE_PATTERNS = [
        'api/auth/2fa/*',
        'api/auth/reset-password',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('X-Idempotency-Key');

        if (! $header) {
            return $next($request);
        }

        $sanitizedKey = preg_replace('/[^A-Za-z0-9_\-]/', '', $header);

        if ($sanitizedKey === '' || strlen($sanitizedKey) > self::MAX_KEY_LENGTH) {
            return response()->json(['message' => 'Invalid X-Idempotency-Key'], 400);
        }

        $scope = $this->resolveScope($request);
        $cacheKey = "idem:{$scope}:{$sanitizedKey}";
        $lockKey = "idem-lock:{$scope}:{$sanitizedKey}";

        try {
            return Cache::lock($lockKey, self::LOCK_TTL_SECONDS)->block(
                self::LOCK_BLOCK_SECONDS,
                fn (): Response => $this->executeUnderLock($request, $next, $cacheKey)
            );
        } catch (LockTimeoutException) {
            return response()->json(
                ['message' => 'Concurrent request in progress, please retry'],
                409
            );
        }
    }

    private function resolveScope(Request $request): string
    {
        $user = $request->user();

        if ($user !== null) {
            $organizationId = $user->organization_id ?? 'no-org';

            return "u:{$organizationId}:{$user->id}";
        }

        return 'anon:'.substr(hash('sha256', (string) $request->ip()), 0, 16);
    }

    private function executeUnderLock(Request $request, Closure $next, string $cacheKey): Response
    {
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return response()->json($cached, 200);
        }

        $response = $next($request);

        if ($this->shouldCache($request, $response)) {
            $decoded = json_decode($response->getContent(), true);
            Cache::put($cacheKey, $decoded, self::CACHE_TTL_SECONDS);
        }

        return $response;
    }

    private function shouldCache(Request $request, Response $response): bool
    {
        if (! $response->isSuccessful()) {
            return false;
        }

        $path = $request->path();

        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (Str::is($pattern, $path)) {
                return false;
            }
        }

        return true;
    }
}
