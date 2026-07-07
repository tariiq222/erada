<?php

namespace Tests\Feature\Tasks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Wave-3 Task 3.1 — parametrized 401 sweep for the Tasks module.
 *
 * Every Tasks endpoint under the unified `/api/unified-tasks` prefix requires
 * `auth:sanctum`. Hitting any endpoint without `actingAs` must yield a clean
 * 401 — not a redirect, not a 403, not a 500. This guards against accidental
 * route changes that drop the auth middleware or against new routes being
 * declared outside the `auth:sanctum` group.
 *
 * Mirrors the template T-B (unauthenticated 401) from the coverage plan.
 */
class TasksUnauthenticatedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @dataProvider unauthenticatedEndpointProvider
     */
    #[DataProvider('unauthenticatedEndpointProvider')]
    public function test_endpoint_returns_401_without_acting_as(string $method, string $path): void
    {
        $response = $this->json($method, $path);
        $response->assertStatus(401);
    }

    public static function unauthenticatedEndpointProvider(): array
    {
        // The legacy `/api/tasks/*` shim was removed — only `/api/unified-tasks/*` remains.
        // ID placeholders follow route-model binding: numeric for ids, scalar for already-cast params.
        $endpoints = [
            // Read endpoints (dangerous reads if exposed)
            ['GET', '/api/unified-tasks/my'],
            ['GET', '/api/unified-tasks/stats'],
            ['GET', '/api/unified-tasks'],
            ['GET', '/api/unified-tasks/1'],
            ['GET', '/api/unified-tasks/1/activity-log'],

            // Write endpoints
            ['POST', '/api/unified-tasks'],
            ['PUT', '/api/unified-tasks/1'],
            ['PATCH', '/api/unified-tasks/1'],
            ['DELETE', '/api/unified-tasks/1'],
            ['PATCH', '/api/unified-tasks/1/status'],
            ['PATCH', '/api/unified-tasks/1/assign'],
        ];

        $cases = [];
        foreach ($endpoints as [$method, $path]) {
            $cases["{$method} {$path}"] = [$method, $path];
        }

        return $cases;
    }
}
