<?php

namespace Tests\Unit\Formatters;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Shared\Formatters\ActivityLogFormatter;
use App\Modules\Shared\Models\ActivityLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اختبارات ActivityLogFormatter
 *
 * تتحقق من:
 * - ترجمة أسماء الأحداث بالعربية
 * - ترجمة أسماء الكيانات
 * - ألوان الأحداث
 * - تنسيق السجلات
 */
class ActivityLogFormatterTest extends TestCase
{
    use RefreshDatabase;

    protected ActivityLogFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new ActivityLogFormatter;
    }

    /**
     * ترجمة أحداث CRUD
     */
    public function test_action_labels_for_crud(): void
    {
        $this->assertEquals('إنشاء', $this->formatter->getActionLabel('created'));
        $this->assertEquals('تحديث', $this->formatter->getActionLabel('updated'));
        $this->assertEquals('حذف', $this->formatter->getActionLabel('deleted'));
        $this->assertEquals('استعادة', $this->formatter->getActionLabel('restored'));
    }

    /**
     * ترجمة أحداث المصادقة
     */
    public function test_action_labels_for_auth(): void
    {
        $this->assertEquals('تسجيل دخول', $this->formatter->getActionLabel('login'));
        $this->assertEquals('تسجيل خروج', $this->formatter->getActionLabel('logout'));
        $this->assertEquals('محاولة دخول فاشلة', $this->formatter->getActionLabel('login_failed'));
    }

    /**
     * ترجمة أحداث الأدوار
     */
    public function test_action_labels_for_roles(): void
    {
        $this->assertEquals('تعيين دور', $this->formatter->getActionLabel('role_assigned'));
        $this->assertEquals('إزالة دور', $this->formatter->getActionLabel('role_revoked'));
        $this->assertEquals('تعيين دور نظام', $this->formatter->getActionLabel('system_role_assigned'));
    }

    /**
     * الأحداث غير المعروفة ترجع كما هي
     */
    public function test_unknown_action_returns_as_is(): void
    {
        $this->assertEquals('unknown_action', $this->formatter->getActionLabel('unknown_action'));
    }

    /**
     * ترجمة أنواع الكيانات
     */
    public function test_model_labels(): void
    {
        $this->assertEquals('مستخدم', $this->formatter->getModelLabel('App\\Modules\\Core\\Models\\User'));
        $this->assertEquals('مشروع', $this->formatter->getModelLabel('App\\Modules\\Projects\\Models\\Project'));
        $this->assertEquals('مهمة', $this->formatter->getModelLabel('App\\Modules\\Tasks\\Models\\Task'));
        $this->assertEquals('قسم', $this->formatter->getModelLabel('App\\Modules\\HR\\Models\\Department'));
    }

    /**
     * الكيانات غير المعروفة ترجع اسم الكلاس فقط
     */
    public function test_unknown_model_returns_class_basename(): void
    {
        $this->assertEquals('UnknownModel', $this->formatter->getModelLabel('App\\UnknownModel'));
    }

    /**
     * ألوان أحداث CRUD
     */
    public function test_action_colors_for_crud(): void
    {
        $this->assertEquals('success', $this->formatter->getActionColor('created'));
        $this->assertEquals('info', $this->formatter->getActionColor('updated'));
        $this->assertEquals('danger', $this->formatter->getActionColor('deleted'));
        $this->assertEquals('warning', $this->formatter->getActionColor('restored'));
    }

    /**
     * ألوان أحداث المصادقة
     */
    public function test_action_colors_for_auth(): void
    {
        $this->assertEquals('success', $this->formatter->getActionColor('login'));
        $this->assertEquals('gray', $this->formatter->getActionColor('logout'));
        $this->assertEquals('danger', $this->formatter->getActionColor('login_failed'));
    }

    /**
     * الأحداث غير المعروفة لها لون رمادي
     */
    public function test_unknown_action_returns_gray_color(): void
    {
        $this->assertEquals('gray', $this->formatter->getActionColor('unknown'));
    }

    /**
     * الحصول على جميع التسميات
     */
    public function test_get_all_action_labels(): void
    {
        $labels = $this->formatter->getAllActionLabels();

        $this->assertIsArray($labels);
        $this->assertArrayHasKey('created', $labels);
        $this->assertArrayHasKey('login', $labels);
        $this->assertArrayHasKey('role_assigned', $labels);
    }

    /**
     * تنسيق سجل نشاط
     */
    public function test_format_activity_log(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $department = Department::factory()->create();
        $user = User::factory()->create(['department_id' => $department->id]);

        $log = ActivityLog::create([
            'user_id' => $user->id,
            'action' => 'login',
            'description' => 'تسجيل دخول ناجح',
            'loggable_type' => User::class,
            'loggable_id' => $user->id,
        ]);

        $formatted = $this->formatter->format($log);

        $this->assertArrayHasKey('id', $formatted);
        $this->assertArrayHasKey('action', $formatted);
        $this->assertArrayHasKey('action_label', $formatted);
        $this->assertArrayHasKey('action_color', $formatted);
        $this->assertArrayHasKey('model_label', $formatted);
        $this->assertEquals('تسجيل دخول', $formatted['action_label']);
        $this->assertEquals('success', $formatted['action_color']);
        $this->assertEquals('مستخدم', $formatted['model_label']);
    }
}
