<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\Capability;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CanonicalAdminGateTest extends TestCase
{
    public function test_no_registered_route_uses_legacy_role_or_permission_middleware(): void
    {
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                $this->assertStringStartsNotWith('role:', $middleware, $route->uri());
                $this->assertStringStartsNotWith('permission:', $middleware, $route->uri());
            }
        }

        $this->assertArrayNotHasKey('role', Route::getMiddleware());
        $this->assertArrayNotHasKey('permission', Route::getMiddleware());
        $this->assertFileDoesNotExist(app_path('Modules/Core/Http/Middleware/CheckRole.php'));
        $this->assertFileDoesNotExist(app_path('Modules/Core/Http/Middleware/CheckPermission.php'));
    }

    public function test_core_administration_routes_use_explicit_canonical_capabilities(): void
    {
        $expected = [
            ['PUT', 'api/settings/system', Capability::SETTINGS_EDIT],
            ['GET', 'api/governance-rules', Capability::SETTINGS_MANAGE],
            ['PUT', 'api/governance-rules', Capability::SETTINGS_MANAGE],
            ['GET', 'api/organizations', Capability::CORE_VIEW_ORGANIZATIONS],
            ['POST', 'api/organizations', Capability::CLUSTER_TREE_MANAGE],
            ['GET', 'api/organizations/{organization}', Capability::CORE_VIEW_ORGANIZATIONS],
            ['PUT', 'api/organizations/{organization}', Capability::CLUSTER_TREE_MANAGE],
            ['PATCH', 'api/organizations/{organization}', Capability::CLUSTER_TREE_MANAGE],
            ['DELETE', 'api/organizations/{organization}', Capability::CLUSTER_TREE_MANAGE],
            ['GET', 'api/scope-types', Capability::SETTINGS_VIEW],
            ['GET', 'api/admin/overview', Capability::CORE_VIEW_ORGANIZATIONS],
            ['GET', 'api/admin/security/alerts', Capability::AUDIT_VIEW],
            ['GET', 'api/admin/audit/recent', Capability::AUDIT_VIEW],
        ];

        foreach ($expected as [$method, $uri, $capability]) {
            $route = $this->routeFor($method, $uri);

            $this->assertNotNull($route, "Missing route {$method} {$uri}");
            $this->assertContains('engine_capability:'.$capability, $route->gatherMiddleware(), $uri);
        }
    }

    private function routeFor(string $method, string $uri): ?LaravelRoute
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }
}
