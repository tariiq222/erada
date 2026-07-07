<?php

namespace Tests\Feature\Api\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Wave-3 Task 3.1 — parametrized 401 sweep for the Core module.
 *
 * Core owns authentication, user/role management, organizations, and system
 * settings. Many endpoints are intentionally public (login, health, public
 * settings read, 2fa verify, register flow, password reset). The protected
 * surface — anything mounted under the `auth:sanctum` group — must yield 401
 * when accessed without `actingAs`.
 *
 * Mirrors the template T-B (unauthenticated 401) from the coverage plan.
 *
 * Public routes (skipped — intentionally not behind auth:sanctum):
 *   POST /api/login                                   (throttle:login)
 *   GET  /api/health
 *   GET  /api/settings/system                         (SystemSettings public read)
 *   POST /api/2fa/verify                              (throttle:login)
 *   POST /api/register                                (throttle:login)
 *   POST /api/password/forgot                         (throttle:otp)
 *   POST /api/password/reset                          (throttle:password)
 */
class CoreUnauthenticatedTest extends TestCase
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
            // Auth + profile (write)
            ['POST', '/api/logout'],
            ['PUT', '/api/profile'],
            ['PUT', '/api/profile/password'],
            ['PUT', '/api/user/locale'],
            ['GET', '/api/user'],

            // 2FA status + lifecycle
            ['GET', '/api/2fa/status'],
            ['POST', '/api/2fa/enable'],
            ['POST', '/api/2fa/confirm'],
            ['POST', '/api/2fa/disable'],
            ['POST', '/api/2fa/recovery-codes'],

            // Dashboard (can leak PII)
            ['GET', '/api/dashboard/stats'],
            ['GET', '/api/dashboard/advanced-stats'],
            ['GET', '/api/dashboard/recent-projects'],
            ['GET', '/api/dashboard/overdue-tasks'],
            ['GET', '/api/dashboard/my-upcoming-tasks'],
            ['GET', '/api/dashboard/projects-by-status'],

            // Users — full CRUD + security + roles
            ['GET', '/api/users/list'],
            ['GET', '/api/users/stats'],
            ['GET', '/api/users'],
            ['POST', '/api/users'],
            ['GET', '/api/users/1'],
            ['PUT', '/api/users/1'],
            ['PATCH', '/api/users/1'],
            ['GET', '/api/users/1/security'],
            ['POST', '/api/users/1/unlock'],
            ['DELETE', '/api/users/1'],

            // System roles (super_admin gated inside; auth gate runs first)
            ['GET', '/api/roles'],
            ['GET', '/api/roles/permissions'],
            ['GET', '/api/roles/abilities'],
            ['GET', '/api/roles/scope-options'],
            ['POST', '/api/roles'],
            ['GET', '/api/roles/1'],
            ['PUT', '/api/roles/1'],
            ['DELETE', '/api/roles/1'],
            ['POST', '/api/roles/assign'],

            // Scoped roles user + audit
            ['GET', '/api/scoped-roles/user/1'],
            ['GET', '/api/scoped-roles/audit-logs'],

            // Project-scoped roles (under /projects/{project}/roles)
            ['GET', '/api/projects/1/roles'],
            ['POST', '/api/projects/1/roles'],
            ['PUT', '/api/projects/1/roles/1'],
            ['DELETE', '/api/projects/1/roles/1'],

            // Department-scoped roles
            ['GET', '/api/departments/1/roles'],
            ['POST', '/api/departments/1/roles'],
            ['DELETE', '/api/departments/1/roles/1'],

            // System settings — write (read is public)
            ['PUT', '/api/settings/system'],

            // Organizations (super_admin-only inside; auth runs first)
            ['GET', '/api/organizations'],
            ['POST', '/api/organizations'],
            ['GET', '/api/organizations/1'],
            ['PUT', '/api/organizations/1'],
            ['PATCH', '/api/organizations/1'],
            ['DELETE', '/api/organizations/1'],

            // Scope types (super_admin-only inside; auth runs first)
            ['GET', '/api/scope-types'],
            ['POST', '/api/scope-types'],
            ['GET', '/api/scope-types/1'],
            ['PUT', '/api/scope-types/1'],
            ['PATCH', '/api/scope-types/1'],
            ['DELETE', '/api/scope-types/1'],
        ];

        $cases = [];
        foreach ($endpoints as [$method, $path]) {
            $cases["{$method} {$path}"] = [$method, $path];
        }

        return $cases;
    }
}
