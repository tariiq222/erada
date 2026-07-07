<?php

namespace Tests\Feature\Projects;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Wave-3 Task 3.1 — parametrized 401 sweep for the Projects module.
 *
 * Every Projects endpoint is mounted under `auth:sanctum`. The risks/stakeholders/
 * members sub-resources, project settings, and milestones all flow through the same
 * auth group. Hitting any of them without `actingAs` must yield 401.
 *
 * Mirrors the template T-B (unauthenticated 401) from the coverage plan.
 */
class ProjectsUnauthenticatedTest extends TestCase
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
            // Project-level read endpoints (some leak PII if exposed)
            ['GET', '/api/projects/settings'],
            ['GET', '/api/projects/creatable-departments'],
            ['GET', '/api/projects/assignable-managers'],
            ['GET', '/api/projects/governing-departments'],
            ['GET', '/api/projects'],
            ['GET', '/api/projects/1'],
            ['GET', '/api/projects/1/stats'],
            ['GET', '/api/projects/1/activity-log'],
            ['GET', '/api/projects/1/members'],
            ['GET', '/api/projects/1/stakeholders'],
            ['GET', '/api/projects/1/stakeholders/1'],

            // Project write endpoints (create / update / delete / roles)
            ['PUT', '/api/projects/settings'],
            ['PUT', '/api/projects/governing-departments'],
            ['POST', '/api/projects'],
            ['PUT', '/api/projects/1'],
            ['PATCH', '/api/projects/1'],
            ['PATCH', '/api/projects/1/pdca-phase'],
            ['POST', '/api/projects/1/members'],
            ['PUT', '/api/projects/1/members/1'],
            ['PUT', '/api/projects/1/roles/1'],
            ['POST', '/api/projects/1/stakeholders'],
            ['PUT', '/api/projects/1/stakeholders/1'],
            ['DELETE', '/api/projects/1'],
            ['DELETE', '/api/projects/1/members/1'],
            ['DELETE', '/api/projects/1/stakeholders/1'],

            // Project-level risks (PII / authorized action)
            ['POST', '/api/projects/1/risks'],
            ['PUT', '/api/projects/1/risks/1'],
            ['DELETE', '/api/projects/1/risks/1'],

            // Project expenses (attachment download is a PII-leakage vector)
            ['GET', '/api/projects/1/expenses'],
            ['GET', '/api/projects/1/expenses/summary'],
            ['GET', '/api/projects/1/expenses/1/attachment'],
            ['GET', '/api/projects/1/expenses/1'],
            ['POST', '/api/projects/1/expenses'],
            ['PUT', '/api/projects/1/expenses/1'],
            ['DELETE', '/api/projects/1/expenses/1'],

            // Milestones
            ['GET', '/api/milestones'],
            ['GET', '/api/milestones/1'],
            ['POST', '/api/milestones'],
            ['PUT', '/api/milestones/1'],
            ['PATCH', '/api/milestones/1'],
            ['DELETE', '/api/milestones/1'],
        ];

        $cases = [];
        foreach ($endpoints as [$method, $path]) {
            $cases["{$method} {$path}"] = [$method, $path];
        }

        return $cases;
    }
}
