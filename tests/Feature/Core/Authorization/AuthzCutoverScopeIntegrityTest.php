<?php

namespace Tests\Feature\Core\Authorization;

use App\Console\Commands\AuthzCutoverPreflightCommand;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthzCutoverScopeIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_integrity_fails_and_counts_role_scope_mismatches(): void
    {
        $role = AuthorizationRole::query()->create([
            'name' => 'scope_mismatch',
            'label' => 'Scope mismatch',
            'scope_type' => 'all',
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $role->id,
            'user_id' => $user->id,
            'scope_type' => 'own',
            'scope_id' => null,
            'organization_id' => null,
            'source' => 'manual',
        ]);

        $command = new class extends AuthzCutoverPreflightCommand
        {
            public function inspectCanonicalIntegrity(): array
            {
                return $this->canonicalIntegrity();
            }
        };

        [$passed, $details] = $command->inspectCanonicalIntegrity();

        $this->assertFalse($passed);
        $this->assertContains('role_scope_mismatches=1', $details);
    }
}
