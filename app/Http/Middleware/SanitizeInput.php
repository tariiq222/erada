<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class SanitizeInput
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->query->replace($this->sanitizeArray($request->query->all()));

        if ($request->isJson()) {
            $request->json()->replace($this->sanitizeArray($request->json()->all()));
        } else {
            $request->request->replace($this->sanitizeArray($request->request->all()));
        }

        return $next($request);
    }

    /**
     * @param  array<string|int, mixed>  $input
     * @return array<string|int, mixed>
     */
    private function sanitizeArray(array $input): array
    {
        foreach ($input as $key => $value) {
            if ($this->shouldSkipKey($key)) {
                continue;
            }

            $input[$key] = $this->sanitizeValue($value);
        }

        return $input;
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->sanitizeArray($value);
        }

        if ($value instanceof UploadedFile || ! is_string($value)) {
            return $value;
        }

        $withoutTags = strip_tags($value);

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $withoutTags) ?? $withoutTags;
    }

    private function shouldSkipKey(string|int $key): bool
    {
        if (! is_string($key)) {
            return false;
        }

        $normalizedKey = strtolower(str_replace(['-', '.'], '_', $key));

        return str_contains($normalizedKey, 'password') || str_contains($normalizedKey, 'token');
    }
}
