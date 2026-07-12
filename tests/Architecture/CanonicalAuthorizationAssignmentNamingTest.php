<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

class CanonicalAuthorizationAssignmentNamingTest extends TestCase
{
    public function test_core_routes_expose_only_canonical_assignment_endpoints(): void
    {
        $routes = file_get_contents(__DIR__.'/../../app/Modules/Core/Routes/api.php');

        $this->assertStringContainsString(
            'AuthorizationRoleAssignmentController::class',
            $routes,
        );
        $this->assertStringContainsString(
            "Route::prefix('authorization-role-assignments')",
            $routes,
        );
        $this->assertStringNotContainsString(
            "Route::prefix('scoped-roles')",
            $routes,
            'The removed legacy assignment route family must not be reintroduced.',
        );
    }

    public function test_user_resource_never_serializes_legacy_authorization_relationships(): void
    {
        $resource = file_get_contents(__DIR__.'/../../app/Modules/Core/Http/Resources/UserResource.php');

        $this->assertStringNotContainsString("'scoped_roles'", $resource);
        $this->assertStringNotContainsString("'roles' =>", $resource);
        $this->assertStringNotContainsString("'pivot' =>", $resource);
        $this->assertStringNotContainsString('model_has_scoped_roles', $resource);
    }
}
