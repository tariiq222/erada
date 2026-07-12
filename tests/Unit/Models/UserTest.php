<?php

namespace Tests\Unit\Models;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRoleAssignment;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    public function test_user_exposes_canonical_role_names(): void
    {
        $this->grantCanonicalAdmin($this->user);

        $this->assertSame(['admin'], $this->user->canonicalRoleNames());
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
    public function test_user_can_read_multiple_canonical_roles(): void
    {
        $this->assignCanonicalRole($this->user, 'viewer');
        $this->grantCanonicalAdmin($this->user);

        $this->assertEqualsCanonicalizing(['viewer', 'admin'], $this->user->canonicalRoleNames());
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
        $this->grantCanonicalSuperAdmin($this->user);

        $this->assertTrue($this->user->isSuperAdmin());
    }

    /**
     * Regression: a super_admin role whose declared scope_type is NOT 'all'
     * but whose assignment is scope_type=all + scope_id=null MUST NOT
     * satisfy isSuperAdmin(). The role's declared scope_type is authoritative;
     * a malformed row that declares scope_type='organization' cannot escalate
     * to system-wide super admin just because its assignment is shaped 'all'.
     */
    public function test_is_super_admin_rejects_malformed_role_with_non_all_declared_scope(): void
    {
        $organization = Organization::factory()->create();
        $this->user->forceFill(['organization_id' => $organization->id])->save();

        $malformedRole = AuthorizationRole::query()->create([
            'name' => 'malformed_super_admin',
            'label' => 'Malformed super admin',
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ORGANIZATION,
            'is_admin_role' => true,
            'is_system' => true,
            'is_active' => true,
        ]);

        AuthorizationRoleAssignment::query()->create([
            'authorization_role_id' => $malformedRole->id,
            'user_id' => $this->user->id,
            'scope_type' => AuthorizationRoleAssignment::SCOPE_ALL,
            'scope_id' => null,
            'organization_id' => null,
            'source' => 'manual',
        ]);

        AccessDecision::flushUserCache($this->user->id);

        $this->assertFalse($this->user->isSuperAdmin());
    }

    /**
     * Regression: the canonical all/all/null super-admin assignment MUST still
     * pass isSuperAdmin() after the role.scope_type='all' hardening.
     */
    public function test_is_super_admin_accepts_canonical_all_scope_role_and_assignment(): void
    {
        $this->grantCanonicalSuperAdmin($this->user);

        $this->assertTrue($this->user->isSuperAdmin());
    }

    /**
     * اختبار isAdmin
     */
    public function test_is_admin(): void
    {
        $organization = Organization::factory()->create();
        $this->user->forceFill(['organization_id' => $organization->id])->save();

        // isAdmin() is a target-free canonical decision. Give the user a real
        // organization context and an organization-scoped admin capability.
        $this->grantEngineCapability(
            $this->user,
            Capability::SETTINGS_MANAGE,
            'organization',
            $organization->id,
        );

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
