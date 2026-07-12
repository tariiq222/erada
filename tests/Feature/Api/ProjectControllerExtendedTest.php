<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProjectControllerExtendedTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected User $member;

    protected Department $department;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();

        $this->admin = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->admin);

        $this->member = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->assignCanonicalRole($this->member, 'viewer');

        $this->project = Project::factory()->create([
            'department_id' => $this->department->id,
        ]);
    }

    // ========== stats ==========

    public function test_can_get_project_stats(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/projects/{$this->project->id}/stats");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'total_tasks',
                'completed_tasks',
                'overdue_tasks',
                'members_count',
                'milestones_count',
                'progress',
            ]);
    }

    public function test_stats_returns_404_for_nonexistent_project(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/projects/99999/stats');

        $response->assertStatus(404);
    }

    // ========== settings ==========

    public function test_can_get_project_settings(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/projects/settings');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'project' => ['default_status'],
                'attachments' => ['max_size_mb', 'allowed_types'],
            ]);
    }

    // ========== activity log ==========

    public function test_can_get_project_activity_log(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/projects/{$this->project->id}/activity-log");

        $response->assertStatus(200);
    }

    // ========== members ==========

    public function test_can_get_project_members(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/projects/{$this->project->id}/members");

        $response->assertStatus(200);
    }

    public function test_can_add_member_to_project(): void
    {
        $newUser = User::factory()->create(['department_id' => $this->department->id, 'is_active' => true]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/members", [
                'user_id' => $newUser->id,
                'role' => 'member',
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'تم إضافة العضو بنجاح']);
    }

    public function test_cannot_add_duplicate_member(): void
    {
        $this->assignCanonicalRole($this->member, 'project_member', 'project', $this->project->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/members", [
                'user_id' => $this->member->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'هذا العضو موجود بالفعل في المشروع']);
    }

    public function test_add_member_requires_valid_user(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/members", [
                'user_id' => 99999,
            ]);

        $response->assertStatus(422);
    }

    public function test_can_remove_member_from_project(): void
    {
        $this->assignCanonicalRole($this->member, 'project_member', 'project', $this->project->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/projects/{$this->project->id}/members/{$this->member->id}");

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'تم حذف العضو بنجاح']);
    }

    // ========== stakeholders ==========

    public function test_can_get_project_stakeholders(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/projects/{$this->project->id}/stakeholders");

        $response->assertStatus(200);
    }

    public function test_can_add_stakeholder_to_project(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/stakeholders", [
                'name' => 'أحمد محمد',
                'role' => 'end_user',
                'organization' => 'شركة التقنية',
                'email' => 'ahmed@example.com',
            ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['message' => 'تم إضافة صاحب المصلحة بنجاح']);
    }

    public function test_add_stakeholder_requires_name_and_role(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/stakeholders", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'role']);
    }

    public function test_can_get_single_stakeholder(): void
    {
        $stakeholder = $this->project->stakeholders()->create([
            'name' => 'صاحب مصلحة',
            'role' => 'end_user',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/projects/{$this->project->id}/stakeholders/{$stakeholder->id}");

        $response->assertStatus(200);
    }

    public function test_can_update_stakeholder(): void
    {
        $stakeholder = $this->project->stakeholders()->create([
            'name' => 'صاحب مصلحة قديم',
            'role' => 'end_user',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}/stakeholders/{$stakeholder->id}", [
                'name' => 'صاحب مصلحة جديد',
                'role' => 'consultant',
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'تم تحديث صاحب المصلحة بنجاح']);
    }

    public function test_can_remove_stakeholder(): void
    {
        $stakeholder = $this->project->stakeholders()->create([
            'name' => 'صاحب مصلحة',
            'role' => 'end_user',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/projects/{$this->project->id}/stakeholders/{$stakeholder->id}");

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'تم حذف صاحب المصلحة بنجاح']);
    }

    // ========== risks ==========

    public function test_can_add_risk_to_project(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/risks", [
                'risk' => 'خطر تأخير الموارد',
                'probability' => 'high',
                'impact' => 'medium',
                'response' => 'استراتيجية التخفيف',
            ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['message' => 'تم إضافة الخطر بنجاح']);
    }

    public function test_add_risk_requires_probability_and_impact(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/risks", [
                'risk' => 'وصف الخطر',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['probability', 'impact']);
    }

    public function test_can_add_risk_using_description_field(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/risks", [
                'description' => 'خطر بالحقل البديل',
                'probability' => 'low',
                'impact' => 'low',
            ]);

        $response->assertStatus(201);
    }

    public function test_can_update_risk(): void
    {
        $risk = $this->project->risks()->create([
            'risk' => 'خطر أولي',
            'probability' => 'low',
            'impact' => 'low',
            'status' => 'open',
            'order' => 1,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}/risks/{$risk->id}", [
                'risk' => 'خطر محدّث',
                'probability' => 'high',
                'impact' => 'high',
                'status' => 'mitigated',
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'تم تحديث الخطر بنجاح']);
    }

    public function test_can_remove_risk(): void
    {
        $risk = $this->project->risks()->create([
            'risk' => 'خطر للحذف',
            'probability' => 'low',
            'impact' => 'low',
            'status' => 'open',
            'order' => 1,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/projects/{$this->project->id}/risks/{$risk->id}");

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'تم حذف الخطر بنجاح']);
    }

    // ========== authorization ==========

    public function test_unauthenticated_cannot_get_stats(): void
    {
        $response = $this->getJson("/api/projects/{$this->project->id}/stats");
        $response->assertStatus(401);
    }

    public function test_unauthenticated_cannot_add_member(): void
    {
        $response = $this->postJson("/api/projects/{$this->project->id}/members", ['user_id' => 1]);
        $response->assertStatus(401);
    }

    public function test_returns_404_for_nonexistent_project_members(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/projects/99999/members');

        $response->assertStatus(404);
    }

    public function test_returns_404_for_nonexistent_project_stakeholders(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/projects/99999/stakeholders');

        $response->assertStatus(404);
    }
}
