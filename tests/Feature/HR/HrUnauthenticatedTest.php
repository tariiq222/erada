<?php

namespace Tests\Feature\HR;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Wave-3 Task 3.1 — parametrized 401 sweep for the HR module.
 *
 * All HR endpoints are mounted under `auth:sanctum` with engine_capability gates
 * applied at the group level for view-side access. Writing still requires auth.
 * The certificate download endpoint is gated by a signed URL signature and is
 * therefore tested separately (no `auth:sanctum` requirement at the route level).
 *
 * Mirrors the template T-B (unauthenticated 401) from the coverage plan.
 *
 * Public-ish route (skipped — signed URL is its own gate):
 *   GET /api/hr/certificates/{certificate}/download   (middleware('signed'))
 */
class HrUnauthenticatedTest extends TestCase
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
            // Department reads (list/tree can leak structure)
            ['GET', '/api/hr/departments/list'],
            ['GET', '/api/hr/departments/tree'],
            ['GET', '/api/hr/departments/hierarchy'],
            ['GET', '/api/hr/departments/allowed-levels'],
            ['GET', '/api/hr/departments/capacity-roles/available'],
            ['GET', '/api/hr/departments/1/capacity-roles'],
            ['PUT', '/api/hr/departments/1/capacity-roles'],

            // Departments CRUD.
            // NOTE: department update is PUT-only (no PATCH). apiResource('departments')
            // generates index, store, show, update (PUT), destroy.
            ['GET', '/api/hr/departments'],
            ['POST', '/api/hr/departments'],
            ['GET', '/api/hr/departments/1'],
            ['PUT', '/api/hr/departments/1'],
            ['DELETE', '/api/hr/departments/1'],

            // Employees (PII surface). Update is PUT-only (no PATCH route).
            ['GET', '/api/hr/employees/stats'],
            ['GET', '/api/hr/employees'],
            ['GET', '/api/hr/employees/1'],
            ['POST', '/api/hr/employees'],
            ['PUT', '/api/hr/employees/1'],
            ['DELETE', '/api/hr/employees/1'],

            // Employee certificates (private storage — PII)
            ['POST', '/api/hr/employees/1/certificates'],
            ['DELETE', '/api/hr/certificates/1'],
        ];

        $cases = [];
        foreach ($endpoints as [$method, $path]) {
            $cases["{$method} {$path}"] = [$method, $path];
        }

        return $cases;
    }
}
