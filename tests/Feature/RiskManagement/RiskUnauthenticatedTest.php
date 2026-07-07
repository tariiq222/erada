<?php

namespace Tests\Feature\RiskManagement;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Wave-3 Task 3.1 — parametrized 401 sweep for the Risk Management module.
 *
 * All Risk endpoints are mounted under `auth:sanctum` and the URL prefix is
 * `/api/risk-management` (NOT `/api/risks`). The full surface — dashboard,
 * matrix, risk CRUD, assessments, status changes, actions + updates, and the
 * admin settings — must return 401 without `actingAs`.
 *
 * Mirrors the template T-B (unauthenticated 401) from the coverage plan.
 */
class RiskUnauthenticatedTest extends TestCase
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
            // Dashboard / matrix / export
            ['GET', '/api/risk-management/dashboard'],
            ['GET', '/api/risk-management/matrix'],
            ['GET', '/api/risk-management/export/csv'],
            ['GET', '/api/risk-management/export/pdf'],

            // Risk CRUD (literal `create` MUST come before apiResource)
            ['GET', '/api/risk-management/risks/create'],
            ['GET', '/api/risk-management/risks/creatable-departments'],
            ['GET', '/api/risk-management/risks'],
            ['POST', '/api/risk-management/risks'],
            ['GET', '/api/risk-management/risks/1'],
            ['PUT', '/api/risk-management/risks/1'],
            ['PATCH', '/api/risk-management/risks/1'],
            ['DELETE', '/api/risk-management/risks/1'],

            // Assessments
            ['GET', '/api/risk-management/risks/1/assessments'],
            ['POST', '/api/risk-management/risks/1/assessments'],

            // Status changes (read + write)
            ['GET', '/api/risk-management/risks/1/status-changes'],
            ['POST', '/api/risk-management/risks/1/status-changes'],

            // Actions + updates
            ['POST', '/api/risk-management/risks/1/actions'],
            ['GET', '/api/risk-management/actions/1'],
            ['PUT', '/api/risk-management/actions/1'],
            ['PATCH', '/api/risk-management/actions/1'],
            ['DELETE', '/api/risk-management/actions/1'],
            ['GET', '/api/risk-management/actions/1/updates'],
            ['POST', '/api/risk-management/actions/1/updates'],

            // Settings (admin)
            ['GET', '/api/risk-management/settings'],
            ['GET', '/api/risk-management/settings/governing-department'],
            ['PUT', '/api/risk-management/settings/governing-department'],
            ['PATCH', '/api/risk-management/settings/governing-department'],
            ['POST', '/api/risk-management/settings/risk-types'],
            ['PUT', '/api/risk-management/settings/risk-types/1'],
            ['PATCH', '/api/risk-management/settings/risk-types/1'],
            ['DELETE', '/api/risk-management/settings/risk-types/1'],
            ['POST', '/api/risk-management/settings/impact-types'],
            ['PUT', '/api/risk-management/settings/impact-types/1'],
            ['PATCH', '/api/risk-management/settings/impact-types/1'],
            ['DELETE', '/api/risk-management/settings/impact-types/1'],

            // NOTE: /risks/{id}/alerts appears in the task brief but is NOT exposed
            // as a route in app/Modules/RiskManagement/Routes/api.php. Skipped.
        ];

        $cases = [];
        foreach ($endpoints as [$method, $path]) {
            $cases["{$method} {$path}"] = [$method, $path];
        }

        return $cases;
    }
}
