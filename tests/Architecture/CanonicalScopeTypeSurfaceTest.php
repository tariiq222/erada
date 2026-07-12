<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CanonicalScopeTypeSurfaceTest extends TestCase
{
    #[Test]
    public function scope_type_api_is_read_only_and_does_not_depend_on_the_legacy_model(): void
    {
        $controller = file_get_contents(__DIR__.'/../../app/Modules/Core/Http/Controllers/ScopeTypeController.php');
        $routes = file_get_contents(__DIR__.'/../../app/Modules/Core/Routes/api.php');

        $this->assertStringNotContainsString('Models\\ScopeType', $controller);
        $this->assertStringNotContainsString('function store(', $controller);
        $this->assertStringNotContainsString('function update(', $controller);
        $this->assertStringNotContainsString('function destroy(', $controller);
        $this->assertStringNotContainsString("Route::post('/', [ScopeTypeController::class", $routes);
        $this->assertStringNotContainsString("Route::delete('/{scopeType}'", $routes);
    }
}
