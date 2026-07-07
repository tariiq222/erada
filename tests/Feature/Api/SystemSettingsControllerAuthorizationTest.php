<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\EnsureCsrfForStateChangingApi;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\SystemSettings;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * اختبارات صلاحية الوصول إلى SystemSettingsController
 *
 * - GET /api/settings/system → عام (200 حتى بدون تسجيل دخول)
 *   لأنه يُستدعى عند تحميل كل صفحة لإعدادات النظام الآمنة للعرض العام.
 * - PUT /api/settings/system → يتطلب صلاحية edit_settings (أو admin/super_admin).
 *   المستخدم العادي (member/viewer) → 403.
 *
 * ملاحظة: تم تعطيل throttle:admin في هذه الاختبارات لتجنب تأثرها بعدد مرات
 * الاستدعاء في نفس الـ process (الكاش array لا يُمسح بين الاختبارات).
 */
class SystemSettingsControllerAuthorizationTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->department = Department::factory()->create();
        SystemSettings::create(['name' => 'Test App']);
    }

    private function validPayload(): array
    {
        return [
            'name' => 'تطبيق اختبار',
        ];
    }

    protected function disableAdminThrottle(): void
    {
        $this->withoutMiddleware([
            ThrottleRequests::class.':api',
            ThrottleRequests::class.':admin',
            EnsureCsrfForStateChangingApi::class,
        ]);
    }

    public function test_get_system_settings_is_public(): void
    {
        $response = $this->getJson('/api/settings/system');

        $response->assertStatus(200)
            ->assertJsonStructure(['app_name', 'app_logo', 'default_locale', 'supported_locales']);
    }

    public function test_member_cannot_update_system_settings(): void
    {
        $this->disableAdminThrottle();

        $user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $user->assignRole('member');

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/settings/system', $this->validPayload());

        $response->assertStatus(403);
    }

    public function test_viewer_cannot_update_system_settings(): void
    {
        $this->disableAdminThrottle();

        $user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $user->assignRole('viewer');

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/settings/system', $this->validPayload());

        $response->assertStatus(403);
    }

    public function test_user_with_only_view_settings_cannot_update(): void
    {
        $this->disableAdminThrottle();

        $user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        // Engine: grant only SETTINGS_VIEW (NOT SETTINGS_EDIT) — PUT must still 403
        // because SystemSettingsPolicy::update() checks Capability::SETTINGS_EDIT.
        $this->grantEngineCapability($user, Capability::SETTINGS_VIEW);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/settings/system', $this->validPayload());
        $response->assertStatus(403);
    }

    public function test_org_admin_cannot_update_global_system_settings(): void
    {
        $this->disableAdminThrottle();

        $user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        // M-01: global system settings are platform-wide; an org-scoped admin
        // must NOT write them — only super_admin may.
        $user->assignRole('admin');

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/settings/system', $this->validPayload());

        $response->assertStatus(403);
    }

    public function test_super_admin_can_update_system_settings(): void
    {
        $this->disableAdminThrottle();

        $user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $user->assignRole('super_admin');

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/settings/system', $this->validPayload());

        $response->assertStatus(200);
    }

    public function test_unauthenticated_user_cannot_update(): void
    {
        $this->disableAdminThrottle();

        $response = $this->putJson('/api/settings/system', $this->validPayload());

        $response->assertStatus(401);
    }
}
