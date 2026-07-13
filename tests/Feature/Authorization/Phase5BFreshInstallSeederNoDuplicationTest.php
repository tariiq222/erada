<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use Database\Seeders\ScopedDepartmentRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The transitional seeder name provisions canonical capacity roles only. */
class Phase5BFreshInstallSeederNoDuplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_running_seeder_twice_keeps_one_canonical_cluster_auditor(): void
    {
        $this->seed(ScopedDepartmentRolesSeeder::class);
        $this->seed(ScopedDepartmentRolesSeeder::class);

        $role = AuthorizationRole::query()
            ->with('permissions.resource')
            ->where('name', 'cluster_auditor')
            ->sole();

        $this->assertSame('organization', $role->scope_type);
        $this->assertFalse((bool) $role->is_admin_role);
        $this->assertSame(1, AuthorizationRole::query()->where('name', 'cluster_auditor')->count());
        $actions = $role->permissions->pluck('action')->all();
        foreach ([Capability::AUDIT_VIEW, Capability::AUDIT_EXPORT, Capability::CLUSTER_TREE_VIEW, Capability::CLUSTER_TREE_EXPORT] as $capability) {
            $this->assertContains(substr($capability, strrpos($capability, '.') + 1), $actions);
        }
    }
}
