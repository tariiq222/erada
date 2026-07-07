<?php

namespace Tests\Feature\OVR;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Wave-3 Task 3.1 — parametrized 401 sweep for the OVR (Incident Reporting) module.
 *
 * All OVR endpoints are mounted under `auth:sanctum` except the public tracking
 * route GET /api/ovr/track/{reportNumber} which is intentionally anonymous and
 * rate-limited.
 *
 * Mirrors the template T-B (unauthenticated 401) from the coverage plan.
 *
 * Public route (skipped, intentionally not behind auth:sanctum):
 *   GET /api/ovr/track/{reportNumber}
 */
class OvrUnauthenticatedTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('unauthenticatedEndpointProvider')]
    public function test_endpoint_returns_401_without_acting_as(string $method, string $path): void
    {
        $response = $this->json($method, $path);
        $response->assertStatus(401);
    }

    public static function unauthenticatedEndpointProvider(): array
    {
        $endpoints = [
            // Helper / read endpoints (PII dangerous)
            ['GET', '/api/ovr/incidents/creatable-departments'],
            ['GET', '/api/ovr/settings/governing-department'],
            ['PUT', '/api/ovr/settings/governing-department'],
            ['PATCH', '/api/ovr/settings/governing-department'],

            // Incident Reports — CRUD + lifecycle + audit
            ['GET', '/api/ovr/incidents'],
            ['POST', '/api/ovr/incidents'],
            ['GET', '/api/ovr/incidents/recent'],
            ['GET', '/api/ovr/incidents/stats'],
            ['GET', '/api/ovr/incidents/export'],
            ['GET', '/api/ovr/incidents/1'],
            ['PUT', '/api/ovr/incidents/1'],
            ['DELETE', '/api/ovr/incidents/1'],
            ['GET', '/api/ovr/incidents/1/audit'],
            ['PATCH', '/api/ovr/incidents/1/status'],
            ['POST', '/api/ovr/incidents/1/submit'],

            // Participants
            ['POST', '/api/ovr/incidents/1/participants'],
            ['DELETE', '/api/ovr/incidents/1/participants/1'],

            // Comments
            ['GET', '/api/ovr/incidents/1/comments'],
            ['POST', '/api/ovr/incidents/1/comments'],
            ['DELETE', '/api/ovr/incidents/1/comments/1'],

            // Categories
            ['GET', '/api/ovr/categories'],
            ['GET', '/api/ovr/categories/list'],
            ['POST', '/api/ovr/categories'],
            ['PUT', '/api/ovr/categories/1'],
            ['DELETE', '/api/ovr/categories/1'],
            ['POST', '/api/ovr/categories/1/reportable-types'],
        ];

        $cases = [];
        foreach ($endpoints as [$method, $path]) {
            $cases["{$method} {$path}"] = [$method, $path];
        }

        return $cases;
    }
}
