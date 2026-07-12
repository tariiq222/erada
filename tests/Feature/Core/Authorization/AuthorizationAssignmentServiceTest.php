<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\Contracts\AuthorizationAssignmentActorGuard;
use App\Modules\Core\Authorization\Data\AssignmentScope;
use App\Modules\Core\Authorization\Data\AssignmentWrite;
use App\Modules\Core\Authorization\Data\RoleAssignmentWrite;
use App\Modules\Core\Authorization\Exceptions\AuthorizationAssignmentDenied;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Services\AssignmentScopeResolver;
use App\Modules\Core\Authorization\Services\AuthorizationAssignmentService;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_assign_writes_only_the_canonical_table_with_server_derived_provenance(): void
    {
        [$actor, $subject, $role, $department] = $this->fixtures();

        $assignment = $this->service()->assign(
            $actor,
            $subject,
            $role,
            new AssignmentWrite(new AssignmentScope('department', $department->id, true), now()->addDay(), 'manual'),
        );

        $this->assertDatabaseHas('authorization_role_assignments', [
            'id' => $assignment->id,
            'authorization_role_id' => $role->id,
            'user_id' => $subject->id,
            'scope_type' => 'department',
            'scope_id' => $department->id,
            'organization_id' => $subject->organization_id,
            'inherit_to_children' => true,
            'source' => 'manual',
            'granted_by' => $actor->id,
        ]);
        $this->assertDatabaseHas('authorization_assignment_audits', [
            'event' => 'canonical_assignment_assigned',
            'actor_id' => $actor->id,
            'target_user_id' => $subject->id,
            'scope_type' => 'department',
            'scope_id' => $department->id,
            'role' => $role->name,
        ]);
    }

    public function test_assign_is_a_semantic_upsert_not_a_duplicate(): void
    {
        [$actor, $subject, $role, $department] = $this->fixtures();
        $service = $this->service();

        $first = $service->assign($actor, $subject, $role, new AssignmentWrite(new AssignmentScope('department', $department->id)));
        $second = $service->assign($actor, $subject, $role, new AssignmentWrite(new AssignmentScope('department', $department->id), now()->addDays(2), 'manual'));

        self::assertSame($first->id, $second->id);
        $this->assertDatabaseCount('authorization_role_assignments', 1);
        self::assertSame('manual', $second->source);
    }

    public function test_it_rejects_inactive_roles_expired_writes_and_cross_organization_scopes(): void
    {
        [$actor, $subject, $role] = $this->fixtures();
        $otherOrganization = Organization::factory()->create();
        $otherDepartment = Department::factory()->create(['organization_id' => $otherOrganization->id]);
        $service = $this->service();

        foreach ([
            fn () => $service->assign($actor, $subject, tap($role, fn ($value) => $value->update(['is_active' => false])), new AssignmentWrite(new AssignmentScope('own', null))),
            fn () => $service->assign($actor, $subject, tap($role, fn ($value) => $value->update(['is_active' => true])), new AssignmentWrite(new AssignmentScope('own', null), now()->subSecond())),
            fn () => $service->assign($actor, $subject, $role, new AssignmentWrite(new AssignmentScope('department', $otherDepartment->id))),
        ] as $write) {
            try {
                $write();
                self::fail('Unsafe canonical assignment was accepted.');
            } catch (AuthorizationAssignmentDenied) {
                self::assertTrue(true);
            }
        }

        $this->assertDatabaseCount('authorization_role_assignments', 0);
    }

    public function test_every_mutation_rejects_a_role_whose_declared_scope_differs_from_the_assignment_scope(): void
    {
        [$actor, $subject, $role, $department] = $this->fixtures();
        $role->update(['scope_type' => 'organization']);
        $service = $this->service();

        foreach ([
            fn () => $service->assign($actor, $subject, $role, new AssignmentWrite(new AssignmentScope('department', $department->id))),
            fn () => $service->syncForRole($actor, $subject, $role, [new AssignmentWrite(new AssignmentScope('department', $department->id))]),
            fn () => $service->syncManual($actor, $subject, [new RoleAssignmentWrite($role, new AssignmentWrite(new AssignmentScope('department', $department->id)))]),
            fn () => $service->revoke($actor, $subject, $role, new AssignmentScope('department', $department->id)),
        ] as $mutation) {
            try {
                $mutation();
                self::fail('A semantically incompatible role assignment was accepted.');
            } catch (AuthorizationAssignmentDenied $exception) {
                self::assertStringContainsString('scope', strtolower($exception->getMessage()));
            }
        }

        $this->assertDatabaseCount('authorization_role_assignments', 0);
    }

    public function test_revoke_and_sync_for_role_are_idempotent_and_scope_bounded(): void
    {
        [$actor, $subject, $role, $department] = $this->fixtures();
        $service = $this->service();
        $departmentTwo = Department::factory()->create(['organization_id' => $subject->organization_id]);

        $service->assign($actor, $subject, $role, new AssignmentWrite(new AssignmentScope('department', $department->id)));
        $service->syncForRole($actor, $subject, $role, [
            new AssignmentWrite(new AssignmentScope('department', $departmentTwo->id)),
        ]);

        $this->assertDatabaseMissing('authorization_role_assignments', ['scope_type' => 'department', 'scope_id' => $department->id]);
        $this->assertDatabaseHas('authorization_role_assignments', ['scope_type' => 'department', 'scope_id' => $departmentTwo->id]);
        self::assertFalse($service->revoke($actor, $subject, $role, new AssignmentScope('department', $department->id)));
        self::assertTrue($service->revoke($actor, $subject, $role, new AssignmentScope('department', $departmentTwo->id)));
    }

    public function test_manual_sync_replaces_manual_assignments_and_preserves_automatic_assignments(): void
    {
        [$actor, $subject, $firstRole, $department] = $this->fixtures();
        $secondRole = AuthorizationRole::create([
            'name' => 'assignment-test-two',
            'label' => 'Assignment Test Two',
            'scope_type' => 'department',
            'is_active' => true,
        ]);
        $service = $this->service();
        $automaticDepartment = Department::factory()->create(['organization_id' => $subject->organization_id]);
        $service->assign($actor, $subject, $firstRole, new AssignmentWrite(
            new AssignmentScope('department', $department->id),
            source: 'manual',
        ));
        $service->assign($actor, $subject, $firstRole, new AssignmentWrite(
            new AssignmentScope('department', $automaticDepartment->id),
            source: 'auto',
        ));

        $result = $service->syncManual($actor, $subject, [
            new RoleAssignmentWrite($secondRole, new AssignmentWrite(
                new AssignmentScope('department', $department->id),
            )),
        ]);

        $this->assertCount(1, $result);
        $this->assertDatabaseMissing('authorization_role_assignments', [
            'authorization_role_id' => $firstRole->id,
            'scope_type' => 'department',
            'source' => 'manual',
        ]);
        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => $firstRole->id,
            'scope_type' => 'department',
            'scope_id' => $automaticDepartment->id,
            'source' => 'auto',
        ]);
        $this->assertDatabaseHas('authorization_role_assignments', [
            'authorization_role_id' => $secondRole->id,
            'scope_type' => 'department',
            'source' => 'manual',
        ]);
    }

    public function test_sync_for_role_only_replaces_manual_rows_and_preserves_non_manual_provenance(): void
    {
        [$actor, $subject, $role, $department] = $this->fixtures();
        $service = $this->service();
        $migrationDepartment = Department::factory()->create(['organization_id' => $subject->organization_id]);
        $manualDepartment = Department::factory()->create(['organization_id' => $subject->organization_id]);

        $auto = $service->assign($actor, $subject, $role, new AssignmentWrite(
            new AssignmentScope('department', $department->id),
            source: 'auto',
        ));
        $migration = $service->assign($actor, $subject, $role, new AssignmentWrite(
            new AssignmentScope('department', $migrationDepartment->id),
            source: 'migration',
        ));
        $manual = $service->assign($actor, $subject, $role, new AssignmentWrite(
            new AssignmentScope('department', $manualDepartment->id),
            source: 'manual',
        ));

        $result = $service->syncForRole($actor, $subject, $role, [
            new AssignmentWrite(new AssignmentScope('department', $department->id)),
        ]);

        $this->assertDatabaseHas('authorization_role_assignments', [
            'id' => $auto->id,
            'source' => 'auto',
        ]);
        $this->assertDatabaseHas('authorization_role_assignments', [
            'id' => $migration->id,
            'source' => 'migration',
        ]);
        $this->assertDatabaseMissing('authorization_role_assignments', [
            'id' => $manual->id,
        ]);
        self::assertSame([$auto->id], array_map(
            static fn ($assignment): int => (int) $assignment->id,
            $result,
        ));
    }

    /** @return array{User, User, AuthorizationRole, Department} */
    private function fixtures(): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create(['organization_id' => $organization->id]);
        $subject = User::factory()->create(['organization_id' => $organization->id]);
        $role = AuthorizationRole::query()->create([
            'name' => 'assignment-test',
            'label' => 'Assignment Test',
            'scope_type' => 'department',
            'is_active' => true,
        ]);
        $department = Department::factory()->create(['organization_id' => $organization->id]);

        return [$actor, $subject, $role, $department];
    }

    private function service(): AuthorizationAssignmentService
    {
        $guard = new class implements AuthorizationAssignmentActorGuard
        {
            public function allows(User $actor, User $subject, AuthorizationRole $role, AssignmentScope $scope): bool
            {
                return true;
            }
        };

        return new AuthorizationAssignmentService($guard, new AssignmentScopeResolver);
    }
}
