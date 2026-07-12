<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Transitional command-name compatibility for deployments and test fixtures
 * that still invoke the former scoped-role seeder directly.
 *
 * Capacity roles now live exclusively in the canonical authorization catalog;
 * this alias deliberately writes no legacy role-definition rows.
 */
class ScopedDepartmentRolesSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
    }
}
