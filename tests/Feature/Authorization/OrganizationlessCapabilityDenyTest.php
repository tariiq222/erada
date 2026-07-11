<?php

namespace Tests\Feature\Authorization;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrganizationlessCapabilityDenyTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_super_admin_without_an_organization_cannot_use_a_target_free_capability(): void
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create(['organization_id' => null]);
        $user->assignRole('admin');

        $this->assertFalse(AccessDecision::can($user->fresh(), Capability::SETTINGS_MANAGE));
    }
}
