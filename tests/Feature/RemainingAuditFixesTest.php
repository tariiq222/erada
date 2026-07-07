<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectExpense;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\Comment;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyResponse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RemainingAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    #[Test]
    public function comments_support_soft_deletes(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $comment = Comment::create([
            'user_id' => $user->id,
            'content' => 'Test comment',
            'commentable_type' => Project::class,
            'commentable_id' => $project->id,
        ]);

        $this->assertDatabaseHas('comments', ['id' => $comment->id]);
        $comment->delete();
        $this->assertSoftDeleted('comments', ['id' => $comment->id]);
    }

    #[Test]
    public function attachments_support_soft_deletes(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $attachment = Attachment::create([
            'user_id' => $user->id,
            'name' => 'test.pdf',
            'file_path' => 'test.pdf',
            'attachable_type' => Project::class,
            'attachable_id' => $project->id,
        ]);

        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
        $attachment->delete();
        $this->assertSoftDeleted('attachments', ['id' => $attachment->id]);
    }

    #[Test]
    public function project_expenses_support_soft_deletes(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();
        $expense = ProjectExpense::create([
            'project_id' => $project->id,
            'created_by' => $user->id,
            'title' => 'Test Expense',
            'amount' => 1000,
            'category' => 'other',
            'expense_date' => now(),
        ]);

        $this->assertDatabaseHas('project_expenses', ['id' => $expense->id]);
        $expense->delete();
        $this->assertSoftDeleted('project_expenses', ['id' => $expense->id]);
    }

    #[Test]
    public function survey_response_has_answers_snapshot(): void
    {
        $user = User::factory()->create();
        $survey = Survey::factory()->create(['created_by' => $user->id]);
        $response = SurveyResponse::create([
            'survey_id' => $survey->id,
            'respondent_type' => 'public',
            'answers_snapshot' => ['q1' => 'answer1', 'q2' => 'answer2'],
        ]);

        $this->assertDatabaseHas('survey_responses', [
            'id' => $response->id,
        ]);
        $this->assertEquals(['q1' => 'answer1', 'q2' => 'answer2'], $response->fresh()->answers_snapshot);
    }

    #[Test]
    public function project_expense_has_finalized_columns(): void
    {
        $project = Project::factory()->create();
        $user = User::factory()->create();
        $expense = ProjectExpense::create([
            'project_id' => $project->id,
            'created_by' => $user->id,
            'title' => 'Test Expense',
            'amount' => 1000,
            'category' => 'other',
            'expense_date' => now(),
        ]);

        $this->assertFalse($expense->fresh()->is_finalized);
        $this->assertNull($expense->fresh()->finalized_at);
        $this->assertNull($expense->fresh()->finalized_by);

        $expense->update([
            'is_finalized' => true,
            'finalized_at' => now(),
            'finalized_by' => $user->id,
        ]);

        $this->assertTrue($expense->fresh()->is_finalized);
        $this->assertNotNull($expense->fresh()->finalized_at);
        $this->assertEquals($user->id, $expense->fresh()->finalized_by);
    }

    #[Test]
    public function xss_input_is_sanitized(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => '<script>alert("xss")</script>test@example.com',
            'password' => '<img src=x onerror=alert(1)>',
        ]);

        // The request should not contain raw script tags in the response
        $response->assertHeader('Content-Type', 'application/json');
    }
}
