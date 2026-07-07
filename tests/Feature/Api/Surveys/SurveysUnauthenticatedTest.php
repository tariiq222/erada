<?php

namespace Tests\Feature\Api\Surveys;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Wave-3 Task 3.1 — parametrized 401 sweep for the Surveys module.
 *
 * Excludes the public submission flow under `/api/surveys/public/*` which is
 * intentionally unauthenticated (covered separately by PublicSurveyRateLimitTest).
 * Every other Surveys endpoint is mounted under `auth:sanctum` and must return
 * 401 without `actingAs`.
 *
 * Mirrors the template T-B (unauthenticated 401) from the coverage plan.
 *
 * Public routes (skipped, intentionally not behind auth:sanctum):
 *   GET    /api/surveys/public/{code}
 *   POST   /api/surveys/public/{code}/submit         (rate-limited)
 *   GET    /api/surveys/public/invitation/{token}
 *   POST   /api/surveys/public/invitation/{token}/submit  (rate-limited)
 */
class SurveysUnauthenticatedTest extends TestCase
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
            // Mapping targets (read; lists internal table names — sensitive)
            ['GET', '/api/surveys/mapping-targets'],

            // Stats
            ['GET', '/api/surveys/stats'],

            // Survey CRUD
            ['GET', '/api/surveys'],
            ['POST', '/api/surveys'],
            ['GET', '/api/surveys/1'],
            ['PUT', '/api/surveys/1'],
            ['PATCH', '/api/surveys/1'],
            ['DELETE', '/api/surveys/1'],

            // Survey lifecycle (write/dangerous-read endpoints)
            ['POST', '/api/surveys/1/publish'],
            ['POST', '/api/surveys/1/close'],
            ['POST', '/api/surveys/1/new-revision'],
            ['GET', '/api/surveys/1/analytics'],
            ['GET', '/api/surveys/1/export'],
            ['GET', '/api/surveys/1/revisions'],

            // Sections
            ['GET', '/api/surveys/1/sections'],
            ['POST', '/api/surveys/1/sections'],
            ['PUT', '/api/surveys/1/sections/1'],
            ['DELETE', '/api/surveys/1/sections/1'],
            ['POST', '/api/surveys/1/sections/reorder'],

            // Fields
            ['GET', '/api/surveys/1/fields'],
            ['POST', '/api/surveys/1/fields'],
            ['PUT', '/api/surveys/1/fields/1'],
            ['DELETE', '/api/surveys/1/fields/1'],
            ['POST', '/api/surveys/1/fields/reorder'],

            // Responses
            ['GET', '/api/surveys/1/responses'],
            ['GET', '/api/surveys/1/responses/1'],
            ['POST', '/api/surveys/1/responses/1/flag'],
            ['POST', '/api/surveys/1/responses/1/review'],

            // Invitations
            ['GET', '/api/surveys/1/invitations'],
            ['POST', '/api/surveys/1/invitations'],
            ['POST', '/api/surveys/1/invitations/bulk'],
            ['POST', '/api/surveys/1/invitations/1/resend'],
            ['DELETE', '/api/surveys/1/invitations/1'],
            ['POST', '/api/surveys/1/invitations/1/revoke'],

            // Mappings
            ['GET', '/api/surveys/1/mappings'],
            ['POST', '/api/surveys/1/mappings'],
            ['PUT', '/api/surveys/1/mappings/1'],
            ['DELETE', '/api/surveys/1/mappings/1'],

            // Data Imports — top-level (read) + review actions
            ['GET', '/api/data-imports'],
            ['GET', '/api/data-imports/1'],
            ['POST', '/api/data-imports/1/approve'],
            ['POST', '/api/data-imports/1/reject'],
            ['POST', '/api/data-imports/bulk-approve'],
            ['POST', '/api/data-imports/bulk-reject'],
            ['POST', '/api/data-imports/1/apply'],
            ['POST', '/api/data-imports/1/retry'],
        ];

        $cases = [];
        foreach ($endpoints as [$method, $path]) {
            $cases["{$method} {$path}"] = [$method, $path];
        }

        return $cases;
    }
}
