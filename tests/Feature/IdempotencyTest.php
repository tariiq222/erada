<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();
        $this->user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->user);

        // نضيف middleware الـ idempotency على route إنشاء المشاريع للاختبار
        Route::middleware(['auth:sanctum', 'idempotency'])
            ->post('api/test-idempotent-projects', function () {
                return response()->json([
                    'id' => Project::factory()->create([
                        'department_id' => request()->user()->department_id,
                        'name' => request('name', 'مشروع افتراضي'),
                    ])->id,
                ], 201);
            });
    }

    /**
     * اختبار أن نفس الطلب مرتين بنفس مفتاح الـ idempotency يُرجع نفس النتيجة
     */
    public function test_same_request_with_same_idempotency_key_returns_same_result(): void
    {
        $key = 'test-key-'.uniqid();

        $payload = [
            'name' => 'مشروع اختبار الايدمبوتنسي',
        ];

        $first = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/test-idempotent-projects', $payload, [
                'X-Idempotency-Key' => $key,
            ]);

        $first->assertStatus(201);
        $firstId = $first->json('id');
        $this->assertNotNull($firstId);

        $second = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/test-idempotent-projects', $payload, [
                'X-Idempotency-Key' => $key,
            ]);

        $second->assertStatus(200);
        $secondId = $second->json('id');

        $this->assertEquals($firstId, $secondId);
    }

    /**
     * اختبار أن طلب بدون مفتاح idempotency ينفذ الطبيعي
     */
    public function test_request_without_idempotency_key_executes_normally(): void
    {
        $first = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/test-idempotent-projects', [
                'name' => 'مشروع بدون مفتاح 1',
            ]);

        $first->assertStatus(201);

        $second = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/test-idempotent-projects', [
                'name' => 'مشروع بدون مفتاح 2',
            ]);

        $second->assertStatus(201);

        $this->assertNotEquals($first->json('id'), $second->json('id'));
    }
}
