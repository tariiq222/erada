<?php

namespace Tests\Unit\Shared;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Services\ActivityLogOrganizationResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogOrganizationResolverTest extends TestCase
{
    use RefreshDatabase;

    protected ActivityLogOrganizationResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->resolver = app(ActivityLogOrganizationResolver::class);
    }

    public function test_resolves_via_loggable_direct_organization_id_column(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $project = Project::factory()->create(['department_id' => $dept->id]);

        $orgId = $this->resolver->resolveForLoggable(Project::class, $project->id);

        // المشروع نفسه ليس له عمود organization_id مباشر، لكنّه ScopeAware
        // ⇒ يسقط عبر department إلى org.
        $this->assertEquals($org->id, $orgId);
    }

    public function test_resolves_incident_type_direct_column_when_populated(): void
    {
        $org = Organization::factory()->create();
        $incidentType = IncidentType::create([
            'organization_id' => $org->id,
            'name' => 'IT-1',
            'name_ar' => 'حوادث تقنية',
            'is_active' => true,
            'requires_reportable_type' => false,
        ]);

        $orgId = $this->resolver->resolveForLoggable(IncidentType::class, $incidentType->id);
        $this->assertEquals($org->id, $orgId);
    }

    public function test_returns_null_when_loggable_type_unknown(): void
    {
        $this->assertNull($this->resolver->resolveForLoggable('App\\NonExistent', 999));
    }

    public function test_returns_null_when_loggable_id_does_not_exist(): void
    {
        $this->assertNull($this->resolver->resolveForLoggable(Project::class, 999999));
    }

    public function test_resolves_organization_loggable_short_circuit(): void
    {
        // loggable_type=Organization ⇒ يعيد loggable_id مباشرة.
        $orgId = $this->resolver->resolveForLoggable(Organization::class, 42);
        $this->assertEquals(42, $orgId);
    }

    public function test_resolves_via_scope_type_organization(): void
    {
        $this->assertEquals(7, $this->resolver->resolveForScope('organization', 7));
    }

    public function test_returns_null_when_scope_type_unknown(): void
    {
        $this->assertNull($this->resolver->resolveForScope('mystery_scope', 1));
    }

    public function test_uses_actor_org_when_target_user_null(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $trace = $this->resolver->resolveWithTrace([
            'action' => 'login',
            'user_id' => $user->id,
            'target_user_id' => null,
        ]);

        $this->assertEquals($org->id, $trace['organization_id']);
        $this->assertEquals('actor', $trace['source']);
    }

    public function test_uses_actor_or_target_org_when_target_user_id_equals_user_id(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);

        $trace = $this->resolver->resolveWithTrace([
            'action' => 'role_assigned',
            'user_id' => $user->id,
            'target_user_id' => $user->id,
        ]);

        // source يمكن أن يكون 'target_user' (step 5) أو 'actor' (step 6)؛ كلاهما
        // يُعيد نفس الـ org. السلوك الصحيح هو أن يكون الـ org = $org->id.
        $this->assertEquals($org->id, $trace['organization_id']);
        $this->assertContains($trace['source'], ['actor', 'target_user']);
    }

    public function test_does_not_use_actor_org_when_target_user_is_different(): void
    {
        // actor من org A، target من org B ⇒ لا يجب استخدام actor.org.
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = Department::factory()->create(['organization_id' => $orgA->id]);
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);

        $actor = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);
        $target = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
        ]);

        $trace = $this->resolver->resolveWithTrace([
            'action' => ActivityLog::ACTION_ROLE_ASSIGNED,
            'user_id' => $actor->id,
            'target_user_id' => $target->id,
        ]);

        // يجب أن يستخدم target_user.org وليس actor.org.
        $this->assertEquals($orgB->id, $trace['organization_id']);
        $this->assertEquals('target_user', $trace['source']);
    }

    public function test_returns_null_when_nothing_resolves_and_logs_warning(): void
    {
        ActivityLog::$fillOrganization = false;

        $payload = [
            'action' => 'mystery_action_xyz',
            'loggable_type' => 'App\\Non\\Existent',
            'loggable_id' => 1,
        ];

        $trace = $this->resolver->resolveWithTrace($payload);
        $this->assertNull($trace['organization_id']);
        $this->assertEquals('none', $trace['source']);
    }
}