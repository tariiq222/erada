<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\Comment;
use App\Modules\Tasks\Models\Task;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T-E side-effect: PUT /api/unified-tasks/{id} must auto-create a task comment
 * when the status transitions into `in_review` (carrying status_comment) or
 * `completed` (carrying challenges/lessons_learned). The wiring lives in
 * TaskController::update() lines 225-249.
 *
 * The controller does NOT create a comment if the task was already in the
 * target status (idempotency: $oldStatus !== 'in_review' / !== 'completed').
 */
class TaskStatusCommentSideEffectTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected Department $dept;

    protected User $user;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $this->user = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->user);

        $this->project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'status' => 'in_progress',
        ]);
    }

    private function makeTask(string $status): Task
    {
        return Task::factory()->create([
            'project_id' => $this->project->id,
            'type' => 'project',
            'status' => $status,
            'assigned_to' => $this->user->id,
        ]);
    }

    // ============================================================
    // in_review transition
    // ============================================================

    public function test_transitioning_to_in_review_creates_status_comment(): void
    {
        $task = $this->makeTask('in_progress');

        $this->assertSame(0, $task->comments()->count(), 'precondition: no comments yet');

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/unified-tasks/{$task->id}", [
                'status' => 'in_review',
                'status_comment' => 'الكود جاهز للمراجعة، تم تشغيل الـ tests محلياً',
            ])
            ->assertStatus(200);

        $this->assertSame(1, $task->comments()->count(), 'one auto-comment must be created');

        $comment = $task->comments()->first();
        $this->assertSame($this->user->id, $comment->user_id);
        $this->assertStringContainsString(
            'سبب الإرسال للمراجعة',
            $comment->content
        );
        $this->assertStringContainsString(
            'الكود جاهز للمراجعة',
            $comment->content
        );
    }

    public function test_re_entering_in_review_does_not_create_a_duplicate_comment(): void
    {
        // Idempotency guard: the controller checks $oldStatus !== 'in_review'.
        // A second PUT that keeps the status at in_review must NOT create a
        // second auto-comment (only the explicit user comment, if any, would
        // land via the standard comment API — not via this code path).
        $task = $this->makeTask('in_review');

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/unified-tasks/{$task->id}", [
                'status' => 'in_review',
                'status_comment' => 'تأكيد إضافي',
            ])
            ->assertStatus(200);

        $this->assertSame(0, $task->comments()->count(), 'no auto-comment when status is unchanged');
    }

    // ============================================================
    // completed transition
    // ============================================================

    public function test_transitioning_to_completed_creates_completion_comment_with_lessons_and_challenges(): void
    {
        $task = $this->makeTask('in_progress');

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/unified-tasks/{$task->id}", [
                'status' => 'completed',
                'challenges' => 'صعوبة في بيئة الـ staging',
                'lessons_learned' => 'تشغيل الـ CI قبل الـ merge',
            ])
            ->assertStatus(200);

        $this->assertSame(1, $task->comments()->count());

        $comment = $task->comments()->first();
        $this->assertSame($this->user->id, $comment->user_id);
        $this->assertStringContainsString('التحديات وكيف تم حلها', $comment->content);
        $this->assertStringContainsString('صعوبة في بيئة الـ staging', $comment->content);
        $this->assertStringContainsString('الدروس المستفادة', $comment->content);
        $this->assertStringContainsString('تشغيل الـ CI قبل الـ merge', $comment->content);
    }

    public function test_transitioning_to_completed_without_challenges_or_lessons_creates_no_comment(): void
    {
        // The auto-comment block is conditional on at least one of
        // challenges/lessons_learned being non-empty.
        $task = $this->makeTask('in_progress');

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/unified-tasks/{$task->id}", [
                'status' => 'completed',
            ])
            ->assertStatus(200);

        $this->assertSame(0, $task->comments()->count());
    }

    public function test_re_entering_completed_does_not_create_a_duplicate_comment(): void
    {
        // Same idempotency guard as in_review: a no-op transition must not
        // double-write a comment.
        $task = $this->makeTask('completed');
        $task->update(['completed_date' => now(), 'progress' => 100]);

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/unified-tasks/{$task->id}", [
                'status' => 'completed',
                'lessons_learned' => 'لن تتكرر',
            ])
            ->assertStatus(200);

        $this->assertSame(0, $task->comments()->count());
    }
}
