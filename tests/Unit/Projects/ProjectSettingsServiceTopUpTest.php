<?php

namespace Tests\Unit\Projects;

use App\Modules\Core\Models\SystemSettings;
use App\Modules\Projects\Services\ProjectSettingsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProjectSettingsServiceTopUpTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_surviving_project_settings_helpers_use_defaults_then_storage_overrides(): void
    {
        $service = new ProjectSettingsService;

        // --- defaults (no SystemSettings row) ---
        $this->assertSame('planning', $service->getDefaultProjectStatus());
        $this->assertSame(10 * 1024 * 1024, $service->getMaxAttachmentSize());
        $this->assertSame(10, $service->getMaxAttachmentSizeMB());
        $this->assertContains('pdf', $service->getAllowedFileTypes());
        // The 8-item CSV is the live default in $defaultProjectSettings.
        $this->assertSame('pdf,doc,docx,xls,xlsx,jpg,png,gif', $service->getAllowedFileTypesString());
        $this->assertSame(['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'png', 'gif'], $service->getAllowedFileTypes());
        $this->assertTrue($service->isFileTypeAllowed('PDF'));
        $this->assertFalse($service->isFileTypeAllowed('exe'));

        // Dead getters must be gone from the public surface.
        $this->assertFalse(
            method_exists($service, 'areMilestonesEnabled'),
            'areMilestonesEnabled was removed: storage key enable_milestones is dead',
        );
        $this->assertFalse(
            method_exists($service, 'areProjectTemplatesEnabled'),
            'areProjectTemplatesEnabled was removed: storage key enable_project_templates is dead',
        );
        $this->assertFalse(
            method_exists($service, 'getDefaultTaskPriority'),
            'getDefaultTaskPriority was removed: storage key default_task_priority is dead',
        );
        $this->assertFalse(
            method_exists($service, 'areTaskDependenciesEnabled'),
            'areTaskDependenciesEnabled was removed: storage key enable_task_dependencies is dead',
        );
        $this->assertFalse(
            method_exists($service, 'isTimeTrackingEnabled'),
            'isTimeTrackingEnabled was removed: storage key enable_time_tracking is dead',
        );
        $this->assertFalse(
            method_exists($service, 'isAutoAssignTasksEnabled'),
            'isAutoAssignTasksEnabled was removed: storage key auto_assign_tasks is dead',
        );
        $this->assertFalse(
            method_exists($service, 'isTaskDeadlineRequired'),
            'isTaskDeadlineRequired was removed: storage key require_task_deadline is dead',
        );

        // --- override by writing through updateProjectSettings ---
        $result = $service->updateProjectSettings([
            'default_project_status' => 'active',
            'max_attachments_size' => 2,
            'allowed_file_types' => 'pdf, png ,xlsx',
        ]);

        $this->assertSame('active', $result['default_project_status']);
        $this->assertSame(2 * 1024 * 1024, $service->getMaxAttachmentSize());
        $this->assertSame(2, $service->getMaxAttachmentSizeMB());
        $this->assertSame('pdf, png ,xlsx', $service->getAllowedFileTypesString());
        $this->assertSame(['pdf', 'png', 'xlsx'], $service->getAllowedFileTypes());
        $this->assertTrue($service->isFileTypeAllowed('PNG'));

        // Dead storage keys must NOT have leaked through updateProjectSettings
        // even when injected by the caller.
        foreach ([
            'enable_milestones',
            'enable_project_templates',
            'default_task_priority',
            'enable_task_dependencies',
            'enable_time_tracking',
            'auto_assign_tasks',
            'require_task_deadline',
        ] as $deadKey) {
            $this->assertArrayNotHasKey(
                $deadKey,
                $result,
                "Dead key {$deadKey} must not be returned from updateProjectSettings",
            );
        }

        // Persistence check: only the 3 live project keys are stored.
        $projects = SystemSettings::first()->settings['projects'] ?? [];
        $this->assertSame('active', $projects['default_project_status']);
        $this->assertSame(2, $projects['max_attachments_size']);
        $this->assertSame('pdf, png ,xlsx', $projects['allowed_file_types']);
        foreach ([
            'enable_milestones',
            'enable_project_templates',
            'default_task_priority',
            'enable_task_dependencies',
            'enable_time_tracking',
            'auto_assign_tasks',
            'require_task_deadline',
        ] as $deadKey) {
            $this->assertArrayNotHasKey($deadKey, $projects, "Dead key {$deadKey} must not be persisted");
        }
    }

    public function test_system_settings_helpers_and_universal_fallbacks_are_unaffected(): void
    {
        $service = new ProjectSettingsService;

        // System-level helpers were not touched by the dead-field cleanup.
        $this->assertFalse($service->isMaintenanceMode());
        $this->assertTrue($service->areNotificationsEnabled());
        $this->assertTrue($service->areEmailNotificationsEnabled());
        $this->assertSame('fallback', $service->getProjectSetting('missing', 'fallback'));
        $this->assertSame('fallback', $service->getSystemSetting('missing', 'fallback'));

        SystemSettings::query()->create([
            'name' => 'Settings',
            'name_en' => 'Settings',
            'settings' => [
                'projects' => [],
                'system' => [
                    'maintenance_mode' => true,
                    'enable_notifications' => false,
                    'enable_email_notifications' => false,
                    'timezone' => 'UTC',
                ],
            ],
        ]);

        $service->clearCache();

        $this->assertTrue($service->isMaintenanceMode());
        $this->assertFalse($service->areNotificationsEnabled());
        $this->assertFalse($service->areEmailNotificationsEnabled());
        $this->assertSame('UTC', $service->getSystemSetting('timezone'));
    }
}
