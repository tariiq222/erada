<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Http\Controllers\RoleController;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RoleControllerUnifiedSourceTest extends TestCase
{
    #[Test]
    public function role_controller_has_no_legacy_role_catalog_dependencies(): void
    {
        $source = file_get_contents((new \ReflectionClass(RoleController::class))->getFileName());

        $this->assertStringContainsString('AuthorizationRole::', $source);
        $this->assertStringContainsString('AuthorizationRolePermission::', $source);
        $this->assertStringNotContainsString(implode('', ['Spatie', '\\Permission']), $source);
        $this->assertStringNotContainsString(implode('', ['Scoped', 'RoleDefinition']), $source);
        $this->assertStringNotContainsString(implode('', ['Scoped', 'Role::']), $source);
        $this->assertStringNotContainsString("DB::table('".implode('', ['permis', 'sions'])."')", $source);
        $this->assertStringNotContainsString("DB::table('".implode('', ['ro', 'les'])."')", $source);
    }
}
