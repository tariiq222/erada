<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اختبارات Middleware EnsureUserIsActive (P3-C)
 *
 * يضمن أن المستخدم الذي تم تعطيل حسابه (`is_active = false`) أو قفله
 * (`locked_until` في المستقبل) بعد إصدار Sanctum Token يحصل على 401
 * عند أول طلب API لاحق، بدل أن يظل التوكن صالحاً حتى انتهاء 24 ساعة.
 *
 * - test_active_user_passes: المستخدم النشط يصل للـ endpoint المحمي.
 * - test_deactivated_user_gets_401: المستخدم المعطَّل → 401 + reason=account_deactivated.
 * - test_locked_user_gets_401: المستخدم المقفل → 401 + reason=account_locked.
 * - test_unauthenticated_request_passes: الطلب غير المُصادَق لا يُحجَب.
 */
class UserStateMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Department $department;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->organization = Organization::factory()->create();
        $this->department = Department::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);
        $this->user->assignRole('member');
    }

    public function test_active_user_passes(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/user');

        $response->assertStatus(200);
    }

    public function test_deactivated_user_gets_401(): void
    {
        $this->user->forceFill(['is_active' => false])->save();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/user');

        $response->assertStatus(401)
            ->assertJson([
                'reason' => 'account_deactivated',
            ])
            ->assertJsonFragment([
                'message' => 'تم تعطيل هذا الحساب. يرجى التواصل مع مدير النظام.',
            ]);
    }

    public function test_locked_user_gets_401(): void
    {
        $this->user->forceFill([
            'failed_login_attempts' => 5,
            'locked_until' => now()->addMinutes(15),
        ])->save();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/user');

        $response->assertStatus(401)
            ->assertJson([
                'reason' => 'account_locked',
            ])
            ->assertJsonFragment([
                'message' => 'الحساب مقفل مؤقتاً. يرجى المحاولة لاحقاً.',
            ]);
    }

    public function test_unauthenticated_request_passes(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200);

        $this->assertNotEquals(
            'account_deactivated',
            $response->json('reason'),
        );
        $this->assertNotEquals(
            'account_locked',
            $response->json('reason'),
        );
    }
}
