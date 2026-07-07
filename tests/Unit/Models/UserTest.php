<?php

namespace Tests\Unit\Models;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class UserTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * اختبار العلاقة مع القسم
     */
    public function test_user_belongs_to_department(): void
    {
        $department = Department::factory()->create();
        $user = User::factory()->create(['department_id' => $department->id]);

        $this->assertInstanceOf(Department::class, $user->department);
        $this->assertEquals($department->id, $user->department->id);
    }

    /**
     * اختبار أن المستخدم يمكن أن يكون له أدوار
     */
    public function test_user_can_have_roles(): void
    {
        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->user->assignRole($role);

        $this->assertTrue($this->user->hasRole('admin'));
        $this->assertCount(1, $this->user->roles);
        $this->assertInstanceOf(Role::class, $this->user->roles->first());
    }

    /**
     * اختبار المستخدم النشط وغير النشط
     */
    public function test_user_active_status(): void
    {
        $activeUser = User::factory()->create(['is_active' => true]);
        $inactiveUser = User::factory()->create(['is_active' => false]);

        $this->assertTrue($activeUser->is_active);
        $this->assertFalse($inactiveUser->is_active);
    }

    /**
     * اختبار التحقق من كلمة المرور
     */
    public function test_password_verification(): void
    {
        $password = 'SecretPassword123!';
        $user = User::factory()->create([
            'password' => bcrypt($password),
        ]);

        $this->assertTrue(\Hash::check($password, $user->password));
        $this->assertFalse(\Hash::check('WrongPassword', $user->password));
    }

    /**
     * اختبار الحقول القابلة للتعبئة
     */
    public function test_fillable_fields(): void
    {
        $department = Department::factory()->create();
        $creator = User::factory()->create();

        $data = [
            'name' => 'اسم المستخدم',
            'email' => 'user@example.com',
            'phone' => '0501234567',
            'extension' => '123',
            'job_title' => 'مطور',
            'department_id' => $department->id,
            'is_active' => true,
            'created_by' => $creator->id,
            'password' => 'password123',
        ];

        $user = User::create($data);

        $this->assertEquals($data['name'], $user->name);
        $this->assertEquals($data['email'], $user->email);
        $this->assertEquals($data['phone'], $user->phone);
        $this->assertEquals($data['department_id'], $user->department_id);
        $this->assertEquals($data['is_active'], $user->is_active);
    }

    /**
     * اختبار إخفاء الحقول الحساسة
     */
    public function test_hidden_fields(): void
    {
        $user = User::factory()->create([
            'password' => 'secret123',
            'remember_token' => 'token123',
        ]);

        $userArray = $user->toArray();

        $this->assertArrayNotHasKey('password', $userArray);
        $this->assertArrayNotHasKey('remember_token', $userArray);
    }

    /**
     * اختبار التحقق من فريدية البريد الإلكتروني
     */
    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        $this->expectException(QueryException::class);

        User::factory()->create(['email' => 'test@example.com']);
    }

    /**
     * اختبار إنشاء توكن API
     */
    public function test_can_create_api_token(): void
    {
        $token = $this->user->createToken('test-token');

        $this->assertNotNull($token);
        $this->assertNotNull($token->plainTextToken);
        $this->assertEquals('test-token', $token->accessToken->name);
        $this->assertEquals($this->user->id, $token->accessToken->tokenable_id);
    }

    /**
     * اختبار حذف توكنات API
     */
    public function test_can_delete_api_tokens(): void
    {
        $this->user->createToken('token1');
        $this->user->createToken('token2');

        $this->assertEquals(2, $this->user->tokens()->count());

        $this->user->tokens()->delete();

        $this->assertEquals(0, $this->user->tokens()->count());
    }

    /**
     * اختبار التحقق من الأدوار المتعددة
     */
    public function test_user_can_check_multiple_roles(): void
    {
        $role1 = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $role2 = Role::firstOrCreate(['name' => 'employee', 'guard_name' => 'web']);

        $this->user->assignRole($role1);
        $this->user->assignRole($role2);

        $this->assertTrue($this->user->hasAllRoles(['manager', 'employee']));
        $this->assertFalse($this->user->hasAllRoles(['manager', 'admin']));
        $this->assertTrue($this->user->hasAnyRole(['manager', 'admin']));
    }

    /**
     * اختبار إزالة الأدوار
     */
    public function test_can_remove_roles(): void
    {
        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user->assignRole($role);
        $this->assertTrue($this->user->hasRole('admin'));

        $this->user->removeRole('admin');
        $this->assertFalse($this->user->hasRole('admin'));
    }

    /**
     * اختبار المستخدم الذي أنشأ الحساب
     */
    public function test_created_by_relationship(): void
    {
        $creator = User::factory()->create();
        $user = User::factory()->create(['created_by' => $creator->id]);

        $this->assertInstanceOf(User::class, $user->creator);
        $this->assertEquals($creator->id, $user->creator->id);
    }

    /**
     * اختبار isSuperAdmin
     */
    public function test_is_super_admin(): void
    {
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $this->user->assignRole($role);

        $this->assertTrue($this->user->isSuperAdmin());
    }

    /**
     * اختبار isAdmin
     */
    public function test_is_admin(): void
    {
        // isAdmin() now routes through AccessDecision::can(SETTINGS_MANAGE); grant
        // the capability on the engine path so the assertion is meaningful.
        $this->grantEngineCapability($this->user, Capability::SETTINGS_MANAGE);

        $this->assertTrue($this->user->isAdmin());
    }

    /**
     * اختبار توليد كلمة مرور آمنة
     */
    public function test_generate_secure_password(): void
    {
        $password = User::generateSecurePassword();

        $this->assertIsString($password);
        $this->assertGreaterThanOrEqual(16, strlen($password));
    }
}
