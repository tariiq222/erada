<?php

namespace Tests\Feature\Projects;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\SystemSettings;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Services\ProjectSettingsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for the saveable project settings endpoint (PUT /api/projects/settings).
 *
 * Storage: SystemSettings::settings['projects'] (JSON blob)
 * Allowed project statuses: draft, planning, in_progress, on_hold, completed, cancelled
 * Allowed attachment types: pdf, jpg, jpeg, png, doc, docx, xls, xlsx, txt, gif
 *   (union of the safe comment-attachment whitelist from StoreCommentRequest and
 *   the existing default list in ProjectSettingsService::$defaultProjectSettings)
 * Authorization: super_admin OR edit_settings permission
 */
class ProjectSettingsUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $settingsEditor;

    protected User $regularUser;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // Seed the org-scoped admin definition so AccessDecision::can(SETTINGS_EDIT)
        // can grant via the Spatie role bridge. RolesAndPermissionsSeeder only
        // creates the Spatie role; the engine needs the scoped_role_definitions row.
        $this->seedAdminScopedRoleDefinition();

        $this->department = Department::factory()->create();

        $this->superAdmin = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->superAdmin->assignRole('super_admin');

        $this->settingsEditor = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        // Engine path: assignRole('admin') routes through AccessDecision via the
        // org-scoped definition seeded above.
        $this->settingsEditor->assignRole('admin');

        $this->regularUser = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->regularUser->assignRole('viewer');
    }

    private function seedAdminScopedRoleDefinition(): void
    {
        $orgScopeId = DB::table('scope_types')->where('key', 'organization')->value('id');

        $definition = ScopedRoleDefinition::firstOrNew([
            'scope_type_id' => $orgScopeId,
            'role_key' => 'admin',
        ]);

        $definition->forceFill([
            'name' => 'organization.admin',
            'display_name' => 'admin',
            'scope_type' => 'organization',
            'label_ar' => 'مدير إدارة',
            'label_en' => 'Admin',
            'permissions' => [
                Capability::SETTINGS_VIEW,
                Capability::SETTINGS_EDIT,
                Capability::SETTINGS_MANAGE,
            ],
            'is_admin_role' => true,
            'is_active' => true,
            'sort_order' => 10,
        ])->save();

        Cache::flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function fullPayload(): array
    {
        return [
            'project' => [
                'default_status' => 'in_progress',
            ],
            'attachments' => [
                'max_size_mb' => 25,
                'allowed_types' => ['pdf', 'docx', 'txt'],
            ],
        ];
    }

    public function test_super_admin_can_update_full_payload_and_persists_to_db(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson('/api/projects/settings', $this->fullPayload());

        $response->assertStatus(200)
            ->assertJsonPath('project.default_status', 'in_progress')
            ->assertJsonPath('attachments.max_size_mb', 25)
            ->assertJsonPath('attachments.allowed_types', ['pdf', 'docx', 'txt']);

        // The trimmed contract MUST NOT include the removed task group or
        // the milestones_enabled / templates_enabled toggles.
        $response->assertJsonMissingPath('project.milestones_enabled')
            ->assertJsonMissingPath('project.templates_enabled')
            ->assertJsonMissingPath('task');

        // The SystemSettings JSON blob must hold the new flat keys with the
        // correct internal naming (default_project_status, not default_status).
        $record = SystemSettings::first();
        $this->assertNotNull($record, 'SystemSettings record should be created on first write');
        $projects = $record->settings['projects'] ?? [];
        $this->assertSame('in_progress', $projects['default_project_status']);
        $this->assertSame(25, $projects['max_attachments_size']);
        // CSV serialised on write, array on read.
        $this->assertSame('pdf,docx,txt', $projects['allowed_file_types']);
        // Dead keys must NOT have been persisted.
        $this->assertArrayNotHasKey('enable_milestones', $projects);
        $this->assertArrayNotHasKey('enable_project_templates', $projects);
        $this->assertArrayNotHasKey('default_task_priority', $projects);
        $this->assertArrayNotHasKey('enable_task_dependencies', $projects);
        $this->assertArrayNotHasKey('enable_time_tracking', $projects);
        $this->assertArrayNotHasKey('auto_assign_tasks', $projects);
        $this->assertArrayNotHasKey('require_task_deadline', $projects);
    }

    public function test_subsequent_get_reflects_persisted_settings(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson('/api/projects/settings', $this->fullPayload())
            ->assertStatus(200);

        // Re-fetch via the read endpoint — proves cache was invalidated and the
        // values came back from storage rather than from a stale in-process cache.
        $get = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/projects/settings');

        $get->assertStatus(200)
            ->assertJsonPath('project.default_status', 'in_progress')
            ->assertJsonPath('attachments.max_size_mb', 25)
            ->assertJsonPath('attachments.allowed_types', ['pdf', 'docx', 'txt'])
            ->assertJsonMissingPath('task');
    }

    public function test_partial_update_only_changes_specified_fields(): void
    {
        // Seed with a full payload so we can later prove that an unrelated field
        // is left untouched by a partial update.
        $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson('/api/projects/settings', $this->fullPayload())
            ->assertStatus(200);

        // Send ONLY attachments.max_size_mb.
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson('/api/projects/settings', [
                'attachments' => ['max_size_mb' => 42],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('attachments.max_size_mb', 42)
            // attachments.allowed_types must hold the value from the prior PUT.
            ->assertJsonPath('attachments.allowed_types', ['pdf', 'docx', 'txt'])
            // The project block must be untouched.
            ->assertJsonPath('project.default_status', 'in_progress');

        // Persistence check.
        $projects = SystemSettings::first()->settings['projects'];
        $this->assertSame(42, $projects['max_attachments_size']);
        $this->assertSame('pdf,docx,txt', $projects['allowed_file_types']);
        $this->assertSame('in_progress', $projects['default_project_status']);
    }

    public function test_user_with_edit_settings_permission_can_update(): void
    {
        $response = $this->actingAs($this->settingsEditor, 'sanctum')
            ->putJson('/api/projects/settings', [
                'project' => ['default_status' => 'completed'],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('project.default_status', 'completed');

        $this->assertSame(
            'completed',
            SystemSettings::first()->settings['projects']['default_project_status'],
        );
    }

    public function test_regular_user_without_edit_settings_is_forbidden(): void
    {
        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->putJson('/api/projects/settings', $this->fullPayload());

        $response->assertStatus(403);

        // No partial state should be persisted from a forbidden request.
        $record = SystemSettings::first();
        if ($record !== null) {
            $this->assertArrayNotHasKey(
                'projects',
                $record->settings ?? [],
                'Forbidden request must not have written to SystemSettings',
            );
        }
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->putJson('/api/projects/settings', $this->fullPayload());

        $response->assertStatus(401);
    }

    public function test_invalid_default_status_returns_422(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson('/api/projects/settings', [
                'project' => ['default_status' => 'archived'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('project.default_status');
    }

    public function test_max_size_mb_below_minimum_returns_422(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson('/api/projects/settings', [
                'attachments' => ['max_size_mb' => 0],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('attachments.max_size_mb');
    }

    public function test_max_size_mb_above_maximum_returns_422(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson('/api/projects/settings', [
                'attachments' => ['max_size_mb' => 101],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('attachments.max_size_mb');
    }

    public function test_disallowed_file_type_returns_422(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson('/api/projects/settings', [
                'attachments' => ['allowed_types' => ['pdf', 'exe']],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('attachments.allowed_types.1');
    }

    public function test_settings_service_update_is_writable_and_returns_flat_array(): void
    {
        // Direct unit-style coverage of the service method (guards against any
        // future refactor that drifts the controller away from the service).
        /** @var ProjectSettingsService $service */
        $service = app(ProjectSettingsService::class);

        $result = $service->updateProjectSettings([
            'default_project_status' => 'on_hold',
            'max_attachments_size' => 50,
        ]);

        $this->assertSame('on_hold', $result['default_project_status']);
        $this->assertSame(50, $result['max_attachments_size']);

        // Other defaults must remain populated (the service merges with the
        // existing settings, not overwrites with the caller-supplied slice).
        $this->assertSame('pdf,doc,docx,xls,xlsx,jpg,png,gif', $result['allowed_file_types']);
    }

    public function test_settings_service_rejects_unknown_keys(): void
    {
        /** @var ProjectSettingsService $service */
        $service = app(ProjectSettingsService::class);

        $result = $service->updateProjectSettings([
            'default_project_status' => 'cancelled',
            'sneaky_injected_key' => 'should-not-be-stored',
        ]);

        $this->assertSame('cancelled', $result['default_project_status']);
        $this->assertArrayNotHasKey('sneaky_injected_key', $result);

        $projects = SystemSettings::first()->settings['projects'] ?? [];
        $this->assertSame('cancelled', $projects['default_project_status']);
        $this->assertArrayNotHasKey('sneaky_injected_key', $projects);
    }
}
