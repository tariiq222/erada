<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header('X-Request-Id');
        $requestId = $this->sanitize($incoming) ?? (string) Str::ulid();

        $request->attributes->set('request_id', $requestId);

        Log::shareContext([
            'request_id' => $requestId,
            'user_id' => $request->user()?->id,
        ]);

        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    private function sanitize(?string $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $cleaned = preg_replace('/[\x00-\x1F\x7F]/', '', $value);

        if ($cleaned === null || $cleaned === '' || strlen($cleaned) > 128) {
            return null;
        }

        return $cleaned;
    }
}
