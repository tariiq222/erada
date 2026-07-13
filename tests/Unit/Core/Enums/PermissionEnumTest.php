<?php

namespace Tests\Unit\Core\Enums;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionEnumTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_role_catalog_is_seeded_without_missing_or_extra_roles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $catalogNames = array_keys(RolesAndPermissionsSeeder::roleCatalog());
        $databaseNames = AuthorizationRole::query()->pluck('name')->all();

        $this->assertEqualsCanonicalizing($catalogNames, $databaseNames);
    }

    public function test_role_catalog_capabilities_belong_to_canonical_capability_set(): void
    {
        $known = Capability::all();

        foreach (RolesAndPermissionsSeeder::roleCatalog() as $name => $definition) {
            $this->assertNotEmpty($definition['capabilities'], "Role {$name} must grant capabilities.");
            $this->assertSame(
                [],
                array_values(array_diff($definition['capabilities'], $known)),
                "Role {$name} references unknown canonical capabilities.",
            );
        }
    }

    public function test_canonical_capabilities_are_non_empty_and_unique(): void
    {
        $capabilities = Capability::all();

        $this->assertSame($capabilities, array_values(array_unique($capabilities)));
        $this->assertNotEmpty($capabilities);
        foreach ($capabilities as $capability) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_-]*(\.[a-z][a-z0-9_-]*)*$/', $capability);
        }
    }
}
