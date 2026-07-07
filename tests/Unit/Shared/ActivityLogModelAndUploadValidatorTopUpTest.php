<?php

namespace Tests\Unit\Shared;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Services\FileUploadValidator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ActivityLogModelAndUploadValidatorTopUpTest extends TestCase
{
    use DatabaseTransactions;

    public function test_activity_log_scopes_labels_relations_and_legacy_static_helpers(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $project = Project::factory()->create(['created_by' => $actor->id]);

        $created = ActivityLog::query()->create([
            'user_id' => $actor->id,
            'action' => ActivityLog::ACTION_CREATED,
            'description' => 'created project',
            'loggable_type' => Project::class,
            'loggable_id' => $project->id,
            'old_values' => ['status' => 'draft'],
            'new_values' => ['status' => 'active'],
            'metadata' => ['source' => 'test'],
        ]);
        ActivityLog::query()->create([
            'user_id' => $actor->id,
            'action' => ActivityLog::ACTION_LOGIN,
            'description' => 'login',
            'loggable_type' => User::class,
            'loggable_id' => $actor->id,
        ]);
        ActivityLog::query()->create([
            'user_id' => $actor->id,
            'target_user_id' => $target->id,
            'action' => ActivityLog::ACTION_ROLE_ASSIGNED,
            'description' => 'role assigned',
            'loggable_type' => User::class,
            'loggable_id' => $target->id,
            'scope_type' => 'project',
            'scope_id' => $project->id,
            'role' => 'manager',
        ]);

        $this->assertSame($actor->id, $created->user->id);
        $this->assertSame($project->id, $created->loggable->id);
        $this->assertSame('إنشاء', $created->action_label);
        $this->assertSame('مشروع', $created->model_label);
        $this->assertIsString($created->action_color);
        $this->assertArrayHasKey(ActivityLog::ACTION_CREATED, ActivityLog::getActionLabels());
        $this->assertArrayHasKey(Project::class, ActivityLog::getModelLabels());
        $this->assertGreaterThanOrEqual(1, ActivityLog::query()->action(ActivityLog::ACTION_CREATED)->count());
        $this->assertGreaterThanOrEqual(1, ActivityLog::query()->forModel(Project::class)->count());
        $this->assertGreaterThanOrEqual(3, ActivityLog::query()->byUser($actor->id)->count());
        $this->assertGreaterThanOrEqual(1, ActivityLog::query()->authEvents()->count());
        $this->assertGreaterThanOrEqual(1, ActivityLog::query()->permissionEvents()->count());
        $this->assertSame(1, ActivityLog::query()->inScope('project', $project->id)->count());
        $this->assertSame(1, ActivityLog::query()->inScope('project')->count());
        $this->assertSame(1, ActivityLog::query()->forTargetUser($target->id)->count());
        $this->assertSame($target->id, ActivityLog::query()->permissionEvents()->first()->targetUser->id);

        $this->assertSame(ActivityLog::ACTION_LOGIN, ActivityLog::logLogin($actor, '127.0.0.1', 'agent')->action);
        $this->assertSame(ActivityLog::ACTION_LOGOUT, ActivityLog::logLogout($actor, '127.0.0.1', 'agent')->action);
        $this->assertSame(ActivityLog::ACTION_LOGIN_FAILED, ActivityLog::logFailedLogin('failed@example.test', '127.0.0.1', 'agent')->action);
        $this->assertSame(ActivityLog::ACTION_PASSWORD_CHANGED, ActivityLog::logPasswordChange($actor, '127.0.0.1', 'agent')->action);
        $this->assertSame(ActivityLog::ACTION_ACCOUNT_SETUP, ActivityLog::logAccountSetup($actor, '127.0.0.1', 'agent')->action);
        $this->assertSame(ActivityLog::ACTION_ROLE_ASSIGNED, ActivityLog::logRoleAssigned($target->id, 'member', 'project', $project->id, $actor->id, 'test')->action);
        $this->assertSame(ActivityLog::ACTION_ROLE_REVOKED, ActivityLog::logRoleRevoked($target->id, 'member', 'project', $project->id, $actor->id, 'test')->action);
        $this->assertSame(ActivityLog::ACTION_SYSTEM_ROLE_ASSIGNED, ActivityLog::logSystemRoleAssigned($target->id, ['admin'], $actor->id, 'test')->action);
        $this->assertSame(ActivityLog::ACTION_SYSTEM_ROLE_REVOKED, ActivityLog::logSystemRoleRevoked($target->id, ['admin'], $actor->id, 'test')->action);
        $this->assertSame(ActivityLog::ACTION_ACCESS_DENIED, ActivityLog::logAccessDenied($target->id, 'delete', 'project', $project->id, 'denied')->action);
    }

    public function test_file_upload_validator_accepts_valid_files_and_rejects_extension_size_and_mime_mismatch(): void
    {
        $validator = new FileUploadValidator;

        $valid = UploadedFile::fake()->createWithContent('note.txt', 'plain text');
        $validator->validate($valid, FileUploadValidator::COMMENT_ATTACHMENT_EXTENSIONS, 1024);
        $this->assertTrue(true);

        $this->expectValidationException(fn () => $validator->validate(
            UploadedFile::fake()->createWithContent('virus.exe', 'plain text'),
            FileUploadValidator::COMMENT_ATTACHMENT_EXTENSIONS,
            1024
        ));

        $this->expectValidationException(fn () => $validator->validate(
            UploadedFile::fake()->createWithContent('too-large.txt', str_repeat('a', 2048)),
            FileUploadValidator::COMMENT_ATTACHMENT_EXTENSIONS,
            1024
        ));

        $this->expectValidationException(fn () => $validator->validate(
            UploadedFile::fake()->createWithContent('fake.pdf', 'not a pdf'),
            FileUploadValidator::COMMENT_ATTACHMENT_EXTENSIONS,
            1024
        ));
    }

    private function expectValidationException(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected validation exception was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }
    }
}
