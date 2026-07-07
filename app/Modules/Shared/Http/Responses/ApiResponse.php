<?php

namespace App\Modules\Shared\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class ApiResponse
{
    /**
     * Return an additive success envelope without moving existing top-level keys.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function success(mixed $data = null, ?string $message = null, array $meta = [], int $status = 200): JsonResponse|JsonResource|ResourceCollection
    {
        if ($data instanceof JsonResource || $data instanceof ResourceCollection) {
            return $data->additional(array_filter([
                'success' => true,
                'message' => $message,
                'meta' => $meta !== [] ? $meta : null,
            ], fn (mixed $value): bool => $value !== null));
        }

        $payload = ['success' => true];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        if (is_array($data) && self::hasTopLevelPayloadKeys($data)) {
            $payload += $data;
        } elseif ($data !== null) {
            $payload['data'] = $data;
        }

        if ($meta !== []) {
            $payload['meta'] = array_merge($payload['meta'] ?? [], $meta);
        }

        return response()->json($payload, $status);
    }

    /**
     * Add `success: true` to an already-shaped top-level payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function successPayload(array $payload, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true] + $payload, $status);
    }

    /**
     * Return an existing-compatible error response with an additive success flag.
     *
     * @param  array<string, mixed>  $errors
     * @param  array<string, mixed>  $meta
     */
    public static function error(string $message, array $errors = [], int $status = 400, array $meta = []): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * Return an error while preserving legacy top-level keys such as `error` or `status`.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function errorPayload(string $message, array $payload = [], int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ] + $payload, $status);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function hasTopLevelPayloadKeys(array $data): bool
    {
        foreach (['data', 'links', 'meta', 'message', 'errors', 'error', 'version_hash'] as $key) {
            if (array_key_exists($key, $data)) {
                return true;
            }
        }

        return false;
    }
}
