<?php

namespace Tests\Feature;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SoftDeletesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * اختبار أن حذف مستخدم لا يحذفه نهائياً وأن بياناته تبقى
     */
    public function test_user_soft_delete_preserves_data(): void
    {
        $department = Department::factory()->create();
        $user = User::factory()->create([
            'department_id' => $department->id,
            'email' => 'preserved@example.com',
            'name' => 'محفوظ البيانات',
            'is_active' => true,
        ]);

        $userId = $user->id;
        $user->delete();

        $this->assertSoftDeleted('users', [
            'id' => $userId,
            'email' => 'preserved@example.com',
            'name' => 'محفوظ البيانات',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => 'preserved@example.com',
        ]);

        $this->assertNull(User::find($userId));
        $this->assertNotNull(User::withTrashed()->find($userId));
    }

    /**
     * اختبار أن حذف قسم لا يحذفه نهائياً
     */
    public function test_department_soft_delete_preserves_record(): void
    {
        $department = Department::factory()->create([
            'name' => 'قسم مؤقت',
            'code' => 'TMP-001',
        ]);

        $departmentId = $department->id;
        $department->delete();

        $this->assertSoftDeleted('departments', [
            'id' => $departmentId,
            'name' => 'قسم مؤقت',
            'code' => 'TMP-001',
        ]);

        $this->assertDatabaseHas('departments', [
            'id' => $departmentId,
            'code' => 'TMP-001',
        ]);

        $this->assertNull(Department::find($departmentId));
        $this->assertNotNull(Department::withTrashed()->find($departmentId));
    }
}
