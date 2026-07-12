<?php

namespace Tests\Feature\Core\Authorization;

use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthorizationAssignmentMetadataSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Authorization assignment metadata schema test is PostgreSQL-only.');
        }
    }

    public function test_assignment_metadata_columns_and_model_relationship_are_available(): void
    {
        $this->assertTrue(Schema::hasColumns('authorization_role_assignments', ['source', 'granted_by']));

        $grantor = User::factory()->create();
        $assignee = User::factory()->create();
        $role = AuthorizationRole::query()->create([
            'name' => 'metadata-test-role',
            'label' => 'Metadata test role',
        ]);

        $assignment = AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $role->id,
            'user_id' => $assignee->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
            'scope_id' => null,
            'granted_by' => $grantor->id,
        ])->refresh();

        $this->assertSame('manual', $assignment->source);
        $this->assertTrue($assignment->grantedBy->is($grantor));

        $grantor->forceDelete();

        $this->assertNull($assignment->refresh()->granted_by);
    }

    public function test_source_accepts_only_supported_provenance_values(): void
    {
        $role = AuthorizationRole::query()->create([
            'name' => 'metadata-source-check-role',
            'label' => 'Metadata source check role',
        ]);
        $assignee = User::factory()->create();

        $this->expectException(QueryException::class);

        AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $role->id,
            'user_id' => $assignee->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
            'scope_id' => null,
            'source' => 'unknown',
        ]);
    }
}
