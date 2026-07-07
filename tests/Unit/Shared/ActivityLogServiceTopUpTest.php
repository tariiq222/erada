<?php

namespace Tests\Unit\Shared;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Services\ActivityLogService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ActivityLogServiceTopUpTest extends TestCase
{
    use DatabaseTransactions;

    public function test_auth_permission_and_crud_events_are_persisted_with_expected_payloads(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        $project = Project::factory()->create(['name' => 'Original']);
        $service = new ActivityLogService;

        $this->assertSame(ActivityLog::ACTION_LOGIN, $service->logLogin($user, '10.0.0.1', 'Agent')->action);
        $this->assertSame(ActivityLog::ACTION_LOGOUT, $service->logLogout($user)->action);
        $failed = $service->logFailedLogin('missing@example.test', '10.0.0.2');
        $this->assertSame('missing@example.test', $failed->metadata['email']);
        $this->assertSame(ActivityLog::ACTION_PASSWORD_CHANGED, $service->logPasswordChange($user)->action);
        $this->assertSame(ActivityLog::ACTION_ACCOUNT_SETUP, $service->logAccountSetup($user)->action);

        $assigned = $service->logRoleAssigned($target->id, 'manager', 'project', $project->id, $user->id, 'needed');
        $this->assertSame(ActivityLog::ACTION_ROLE_ASSIGNED, $assigned->action);
        $this->assertSame(['role' => 'manager'], $assigned->new_values);
        $this->assertSame('needed', $assigned->reason);

        $revoked = $service->logRoleRevoked($target->id, 'manager', 'project', $project->id, $user->id);
        $this->assertSame(['role' => 'manager'], $revoked->old_values);

        $systemAssigned = $service->logSystemRoleAssigned($target->id, ['admin', 'member'], $user->id, 'promotion');
        $this->assertSame('admin, member', $systemAssigned->role);
        $this->assertSame(['roles' => ['admin', 'member']], $systemAssigned->new_values);
        $this->assertSame(ActivityLog::ACTION_SYSTEM_ROLE_REVOKED, $service->logSystemRoleRevoked($target->id, 'member', $user->id)->action);

        $denied = $service->logAccessDenied($target->id, 'delete_project', 'project', $project->id);
        $this->assertSame(ActivityLog::ACTION_ACCESS_DENIED, $denied->action);
        $this->assertSame('delete_project', $denied->role);

        $created = $service->logCreated($project, $user->id, 'created project');
        $this->assertSame(ActivityLog::ACTION_CREATED, $created->action);
        $this->assertSame(Project::class, $created->loggable_type);

        $old = $project->getOriginal();
        $project->name = 'Updated';
        $updated = $service->logUpdated($project, $old, $user->id);
        $this->assertSame(ActivityLog::ACTION_UPDATED, $updated->action);
        $this->assertIsArray($updated->new_values);

        $deleted = $service->logDeleted($project, $user->id);
        $this->assertSame(ActivityLog::ACTION_DELETED, $deleted->action);
        $this->assertSame($project->id, (int) $deleted->loggable_id);
    }

    public function test_activity_log_scopes_labels_relations_and_legacy_static_proxies(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $log = ActivityLog::create([
            'user_id' => $user->id,
            'action' => ActivityLog::ACTION_CREATED,
            'description' => 'Created project',
            'loggable_type' => Project::class,
            'loggable_id' => $project->id,
        ]);

        $this->assertSame(1, ActivityLog::action(ActivityLog::ACTION_CREATED)->whereKey($log->id)->count());
        $this->assertSame(1, ActivityLog::forModel(Project::class)->whereKey($log->id)->count());
        $this->assertSame(1, ActivityLog::byUser($user->id)->whereKey($log->id)->count());
        $this->assertSame($user->id, $log->user->id);
        $this->assertSame($project->id, $log->loggable->id);
        $this->assertNotSame('', $log->action_label);
        $this->assertNotSame('', $log->model_label);
        $this->assertNotSame('', $log->action_color);

        $login = ActivityLog::logLogin($user, '127.0.0.1', 'ProxyAgent');
        $this->assertSame(ActivityLog::ACTION_LOGIN, $login->action);
        $this->assertSame(1, ActivityLog::authEvents()->whereKey($login->id)->count());
        $this->assertSame(ActivityLog::ACTION_LOGIN_FAILED, ActivityLog::logFailedLogin('bad@example.test')->action);
        $this->assertSame(ActivityLog::ACTION_LOGOUT, ActivityLog::logLogout($user)->action);
    }
}
