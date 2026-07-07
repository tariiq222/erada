<?php

namespace Tests\Feature\Api\Strategy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Wave-3 Task 3.1 — parametrized 401 sweep for the Strategy module.
 *
 * The Strategy module exposes Portfolios, Programs, KPIs, Blockers, and PDCA
 * Reviews. NOTE: `/api/strategy/decisions` was moved to `/api/decisions` in
 * phase F (covered in the Meetings sweep). All current Strategy endpoints are
 * mounted under `auth:sanctum` and the URL prefix is `/api/strategy`.
 *
 * Mirrors the template T-B (unauthenticated 401) from the coverage plan.
 */
class StrategyUnauthenticatedTest extends TestCase
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
            // Dashboard (can leak aggregated PII)
            ['GET', '/api/strategy/dashboard/summary'],
            ['GET', '/api/strategy/dashboard/golden-chain/portfolio/1'],

            // Portfolios
            ['GET', '/api/strategy/portfolios/list'],
            ['GET', '/api/strategy/portfolios/summary'],
            ['PUT', '/api/strategy/portfolios/1/priority'],
            ['PUT', '/api/strategy/portfolios/1/strategic-status'],
            ['GET', '/api/strategy/portfolios'],
            ['POST', '/api/strategy/portfolios'],
            ['GET', '/api/strategy/portfolios/1'],
            ['PUT', '/api/strategy/portfolios/1'],
            ['PATCH', '/api/strategy/portfolios/1'],
            ['DELETE', '/api/strategy/portfolios/1'],

            // Programs + project linking
            ['GET', '/api/strategy/programs/list'],
            ['GET', '/api/strategy/programs/unlinked-projects'],
            ['POST', '/api/strategy/programs/1/link-project'],
            ['DELETE', '/api/strategy/programs/1/unlink-project/1'],
            ['GET', '/api/strategy/programs'],
            ['POST', '/api/strategy/programs'],
            ['GET', '/api/strategy/programs/1'],
            ['PUT', '/api/strategy/programs/1'],
            ['PATCH', '/api/strategy/programs/1'],
            ['DELETE', '/api/strategy/programs/1'],

            // Blockers + lifecycle
            ['POST', '/api/strategy/blockers/1/resolve'],
            ['POST', '/api/strategy/blockers/1/escalate'],
            ['GET', '/api/strategy/blockers'],
            ['POST', '/api/strategy/blockers'],
            ['GET', '/api/strategy/blockers/1'],
            ['PUT', '/api/strategy/blockers/1'],
            ['PATCH', '/api/strategy/blockers/1'],
            ['DELETE', '/api/strategy/blockers/1'],

            // PDCA Reviews
            ['GET', '/api/strategy/reviews'],
            ['POST', '/api/strategy/reviews'],
            ['GET', '/api/strategy/reviews/1'],
            ['PUT', '/api/strategy/reviews/1'],
            ['PATCH', '/api/strategy/reviews/1'],
            ['DELETE', '/api/strategy/reviews/1'],
        ];

        $cases = [];
        foreach ($endpoints as [$method, $path]) {
            $cases["{$method} {$path}"] = [$method, $path];
        }

        return $cases;
    }
}
