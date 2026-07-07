<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\KpiLink;
use App\Modules\Projects\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * ProjectMethodologyTest — اختبارات الحقول الجديدة لمنهجيتي المشاريع
 *
 * يغطي البنود السبعة المطلوبة:
 *  1. type=development ينجح ويحفظ حقول PMBOK في DB والـ response
 *  2. type=improvement بلا problem_statement → 422 (تم إصلاح nullable+closure bug)
 *  3. type=improvement مع problem_statement → 201
 *  4. بلا type أو type غير صالح → 422
 *  5. GET /api/projects?type=improvement يرجع التحسينية فقط (عدد + محتوى)
 *  6. تحديث للإغلاق يحفظ حقول الإغلاق في DB
 *  7. المشروع المُنشأ يرث organization_id من المستخدم
 *
 * FIX APPLIED:
 *   StoreProjectRequest::methodologyRules() كان يضع 'nullable' قبل الـ closure في
 *   قواعد problem_statement، مما كان يمنع إطلاق الـ closure عند غياب/null الحقل.
 *   تم إصلاحه باستخدام 'required_if:type,improvement' بدلاً من nullable+closure.
 */
class ProjectMethodologyTest extends TestCase
{
    use DatabaseTransactions;

    protected Organization $org;

    protected Department $dept;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->superAdmin->assignRole('super_admin');
    }

    // =========================================================================
    // بند 1 — type=development ينجح ويحفظ حقول PMBOK في DB والـ response
    // =========================================================================

    public function test_create_new_type_project_stores_pmbok_fields_in_db_and_response(): void
    {
        $payload = [
            'name' => 'مشروع جديد PMBOK',
            'type' => 'development',
            'priority' => 'high',
            'start_date' => '2026-07-01',
            'end_date' => '2026-12-31',
            'business_case' => 'مبرر عمل واضح لهذا المشروع',
            'success_criteria' => ['معيار 1: رضا العميل 90%', 'معيار 2: التسليم في الموعد'],
            'approval_criteria' => 'موافقة مجلس الإدارة',
            'exit_criteria' => 'اجتياز اختبارات القبول',
        ];

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/projects', $payload);

        // التحقق من status code والبنية
        $response->assertStatus(201)
            ->assertJsonPath('project.type', 'development')
            ->assertJsonPath('project.business_case', 'مبرر عمل واضح لهذا المشروع')
            ->assertJsonPath('project.approval_criteria', 'موافقة مجلس الإدارة')
            ->assertJsonPath('project.exit_criteria', 'اجتياز اختبارات القبول');

        // التحقق من DB — الحقول محفوظة فعلياً
        $this->assertDatabaseHas('projects', [
            'name' => 'مشروع جديد PMBOK',
            'type' => 'development',
            'business_case' => 'مبرر عمل واضح لهذا المشروع',
            'approval_criteria' => 'موافقة مجلس الإدارة',
            'exit_criteria' => 'اجتياز اختبارات القبول',
        ]);

        // التحقق من حقل JSON success_criteria في DB
        $project = Project::where('name', 'مشروع جديد PMBOK')->firstOrFail();
        $this->assertIsArray($project->success_criteria);
        $this->assertCount(2, $project->success_criteria);
        $this->assertSame('معيار 1: رضا العميل 90%', $project->success_criteria[0]);
    }

    // =========================================================================
    // بند 2 — type=improvement بلا problem_statement → 422
    // تم إصلاح nullable+closure bug باستخدام required_if:type,improvement
    // =========================================================================

    /**
     * إرسال type=improvement بدون problem_statement يجب أن يرجع 422.
     * تم إصلاح الـ bug السابق (nullable+closure لا يُطلق الـ closure).
     */
    public function test_improvement_without_problem_statement_returns_422(): void
    {
        $payload = [
            'name' => 'مشروع تحسيني بلا مشكلة',
            'type' => 'improvement',
            'priority' => 'medium',
            'start_date' => '2026-07-01',
            'end_date' => '2026-12-31',
            // problem_statement غائب عمداً
        ];

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/projects', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['problem_statement']);

        $this->assertDatabaseMissing('projects', [
            'name' => 'مشروع تحسيني بلا مشكلة',
        ]);
    }

    /**
     * إرسال problem_statement بقيمة null صريحة مع type=improvement يجب أن يرجع 422.
     */
    public function test_improvement_with_explicit_null_problem_statement_returns_422(): void
    {
        $payload = [
            'name' => 'مشروع تحسيني null صريح',
            'type' => 'improvement',
            'priority' => 'medium',
            'start_date' => '2026-07-01',
            'end_date' => '2026-12-31',
            'problem_statement' => null,
        ];

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/projects', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['problem_statement']);

        $this->assertDatabaseMissing('projects', [
            'name' => 'مشروع تحسيني null صريح',
        ]);
    }

    // =========================================================================
    // بند 3 — type=improvement مع problem_statement → 201
    // =========================================================================

    public function test_improvement_type_with_problem_statement_succeeds(): void
    {
        $payload = [
            'name' => 'مشروع تحسيني مكتمل',
            'type' => 'improvement',
            'priority' => 'high',
            'start_date' => '2026-07-01',
            'end_date' => '2026-12-31',
            'problem_statement' => 'معدل الأخطاء في العملية 15% وهو مرتفع',
            'target_process' => 'عملية معالجة الطلبات',
            'root_cause' => 'غياب التدريب وضعف الإجراءات',
            'expected_benefits' => ['خفض الأخطاء إلى 3%', 'توفير 20% من الوقت'],
            'current_pdca_phase' => 'plan',
            // مشاريع التحسين تتطلب مؤشر أداء واحداً على الأقل عند الإنشاء
            'kpis' => [
                ['name' => 'معدل الأخطاء', 'target' => 3, 'baseline' => 15, 'unit' => '%'],
            ],
        ];

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/projects', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('project.type', 'improvement')
            ->assertJsonPath('project.problem_statement', 'معدل الأخطاء في العملية 15% وهو مرتفع')
            ->assertJsonPath('project.target_process', 'عملية معالجة الطلبات')
            ->assertJsonPath('project.current_pdca_phase', 'plan');

        // التحقق من DB
        $this->assertDatabaseHas('projects', [
            'name' => 'مشروع تحسيني مكتمل',
            'type' => 'improvement',
            'problem_statement' => 'معدل الأخطاء في العملية 15% وهو مرتفع',
            'target_process' => 'عملية معالجة الطلبات',
            'root_cause' => 'غياب التدريب وضعف الإجراءات',
            'current_pdca_phase' => 'plan',
        ]);

        // التحقق من حقل JSON expected_benefits
        $project = Project::where('name', 'مشروع تحسيني مكتمل')->firstOrFail();
        $this->assertIsArray($project->expected_benefits);
        $this->assertCount(2, $project->expected_benefits);
    }

    // =========================================================================
    // بند 4 — بلا type أو type غير صالح → 422
    // =========================================================================

    public function test_missing_type_returns_422(): void
    {
        $payload = [
            'name' => 'مشروع بلا نوع',
            'priority' => 'medium',
            'start_date' => '2026-07-01',
            'end_date' => '2026-12-31',
            // type غائب
        ];

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/projects', $payload);

        $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json');

        $errors = $response->json('errors');
        $this->assertNotNull($errors);
        $this->assertArrayHasKey('type', $errors, 'يجب أن يظهر خطأ تحقق على حقل type عند غيابه');

        $this->assertDatabaseMissing('projects', ['name' => 'مشروع بلا نوع']);
    }

    public function test_invalid_type_returns_422(): void
    {
        $payload = [
            'name' => 'مشروع بنوع غير صالح',
            'type' => 'invalid_type',
            'priority' => 'medium',
            'start_date' => '2026-07-01',
            'end_date' => '2026-12-31',
        ];

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/projects', $payload);

        $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json');

        $errors = $response->json('errors');
        $this->assertNotNull($errors);
        $this->assertArrayHasKey('type', $errors, 'يجب أن يظهر خطأ تحقق على type غير الصالح');

        $this->assertDatabaseMissing('projects', ['name' => 'مشروع بنوع غير صالح']);
    }

    // =========================================================================
    // بند 5 — GET ?type=improvement يرجع التحسينية فقط
    // =========================================================================

    public function test_index_filter_by_type_returns_only_improvement_projects(): void
    {
        // إنشاء مشروعين من كل نوع في نفس المؤسسة
        $newProject1 = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'type' => 'development',
            'name' => 'مشروع جديد أول',
        ]);

        $newProject2 = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'type' => 'development',
            'name' => 'مشروع جديد ثاني',
        ]);

        $improvementProject1 = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'type' => 'improvement',
            'name' => 'مشروع تحسيني أول',
        ]);

        $improvementProject2 = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'type' => 'improvement',
            'name' => 'مشروع تحسيني ثاني',
        ]);

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->getJson('/api/projects?type=improvement');

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json');

        // التأكد من أن الـ response JSON (ليس HTML)
        $json = $response->json();
        $this->assertIsArray($json, 'يجب أن يكون الـ response مصفوفة JSON صالحة');

        // استخراج المشاريع من data (paginated) أو مباشرة
        $projects = $json['data'] ?? $json;
        $this->assertIsArray($projects);

        // يجب أن يحتوي على 2 مشاريع تحسينية فقط
        $this->assertCount(2, $projects,
            'يجب أن يرجع فلتر type=improvement مشروعين تحسينيين بالضبط');

        // التحقق من المحتوى: كل المشاريع المرجَعة من نوع improvement
        foreach ($projects as $project) {
            $this->assertSame('improvement', $project['type'],
                'كل المشاريع المُرجَعة يجب أن تكون من نوع improvement');
        }

        // التحقق من وجود المشاريع التحسينية المُنشأة في النتيجة
        $returnedIds = array_column($projects, 'id');
        $this->assertContains($improvementProject1->id, $returnedIds,
            'يجب أن يظهر المشروع التحسيني الأول في النتيجة');
        $this->assertContains($improvementProject2->id, $returnedIds,
            'يجب أن يظهر المشروع التحسيني الثاني في النتيجة');

        // التحقق من غياب المشاريع الجديدة (new) من النتيجة
        $this->assertNotContains($newProject1->id, $returnedIds,
            'يجب ألا يظهر المشروع الجديد في نتيجة فلتر improvement');
        $this->assertNotContains($newProject2->id, $returnedIds,
            'يجب ألا يظهر المشروع الجديد الثاني في نتيجة فلتر improvement');
    }

    // =========================================================================
    // بند 6 — تحديث للإغلاق يحفظ حقول الإغلاق في DB
    // =========================================================================

    public function test_update_project_to_completed_saves_closure_fields_in_db(): void
    {
        // إنشاء مشروع جديد (PMBOK)
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'type' => 'development',
            'status' => 'in_progress',
        ]);

        $updatePayload = [
            'status' => 'completed',
            'lessons_learned' => 'تعلمنا ضرورة التخطيط المبكر للموارد',
            'outcome_summary' => 'تحقق 95% من أهداف المشروع في الموعد المحدد',
            'achievement_status' => 'achieved',
        ];

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$project->id}", $updatePayload);

        $response->assertStatus(200)
            ->assertJsonPath('project.status', 'completed')
            ->assertJsonPath('project.lessons_learned', 'تعلمنا ضرورة التخطيط المبكر للموارد')
            ->assertJsonPath('project.outcome_summary', 'تحقق 95% من أهداف المشروع في الموعد المحدد')
            ->assertJsonPath('project.achievement_status', 'achieved');

        // التحقق من DB
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'status' => 'completed',
            'lessons_learned' => 'تعلمنا ضرورة التخطيط المبكر للموارد',
            'outcome_summary' => 'تحقق 95% من أهداف المشروع في الموعد المحدد',
            'achievement_status' => 'achieved',
        ]);
    }

    public function test_update_improvement_project_to_completed_saves_improvement_closure_fields(): void
    {
        // إنشاء مشروع تحسيني
        $project = Project::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'type' => 'improvement',
            'status' => 'in_progress',
            'problem_statement' => 'مشكلة موجودة',
        ]);

        // An improvement project carries ≥1 KPI from creation; link one so a
        // closure update that omits `kpis` is not blocked by the ≥1 KPI rule.
        $kpi = Kpi::factory()->create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->superAdmin->id,
            'created_by' => $this->superAdmin->id,
        ]);
        (new KpiLink)->forceFill([
            'organization_id' => $this->org->id,
            'kpi_id' => $kpi->id,
            'linkable_type' => Project::class,
            'linkable_id' => $project->id,
            'relationship_type' => 'primary',
            'weight' => 1,
            'created_by' => $this->superAdmin->id,
        ])->save();

        $updatePayload = [
            'status' => 'completed',
            'lessons_learned' => 'الدرس المستفاد: التواصل المبكر مع الفريق',
            'outcome_summary' => 'تم تحقيق هدف خفض الأخطاء',
            // الحقول الإضافية الخاصة بالمشاريع التحسينية
            'sustainability_plan' => 'استمرار التدريب الدوري',
            'achievement_percentage' => 87.5,
            'achievement_status' => 'partial',
        ];

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->putJson("/api/projects/{$project->id}", $updatePayload);

        $response->assertStatus(200)
            ->assertJsonPath('project.status', 'completed')
            ->assertJsonPath('project.sustainability_plan', 'استمرار التدريب الدوري')
            ->assertJsonPath('project.achievement_status', 'partial');

        // التحقق من DB مع جميع حقول الإغلاق
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'status' => 'completed',
            'lessons_learned' => 'الدرس المستفاد: التواصل المبكر مع الفريق',
            'outcome_summary' => 'تم تحقيق هدف خفض الأخطاء',
            'sustainability_plan' => 'استمرار التدريب الدوري',
            'achievement_status' => 'partial',
        ]);

        // التحقق من achievement_percentage كـ decimal
        $project->refresh();
        $this->assertEquals(87.5, (float) $project->achievement_percentage);
    }

    // =========================================================================
    // بند 7 — عزل المؤسسة: المشروع يرث organization_id من المستخدم
    // =========================================================================

    public function test_created_project_inherits_organization_id_from_authenticated_user(): void
    {
        // مستخدم ينتمي لمؤسسة B مختلفة
        $orgB = Organization::factory()->create();
        $deptB = Department::factory()->create(['organization_id' => $orgB->id]);

        $adminInOrgB = User::factory()->create([
            'organization_id' => $orgB->id,
            'department_id' => $deptB->id,
            'is_active' => true,
        ]);
        $adminInOrgB->assignRole('super_admin');

        $payload = [
            'name' => 'مشروع خاص بمؤسسة B',
            'type' => 'development',
            'priority' => 'medium',
            'start_date' => '2026-07-01',
            'end_date' => '2026-12-31',
        ];

        $response = $this->actingAs($adminInOrgB, 'sanctum')
            ->postJson('/api/projects', $payload);

        $response->assertStatus(201)
            ->assertHeader('Content-Type', 'application/json');

        // التحقق من أن المشروع ورث organization_id الصحيح من المستخدم
        $projectId = $response->json('project.id');
        $this->assertNotNull($projectId, 'يجب أن يكون للمشروع ID في الـ response');

        $project = Project::findOrFail($projectId);
        $this->assertSame(
            $orgB->id,
            $project->organization_id,
            'يجب أن يرث المشروع organization_id من المستخدم المُنشئ'
        );

        // التحقق من DB مع organization_id
        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'name' => 'مشروع خاص بمؤسسة B',
            'organization_id' => $orgB->id,
        ]);

        // التحقق السلبي: لا يجب أن يُسند organization_id لمؤسسة أخرى
        $this->assertNotEquals(
            $this->org->id,
            $project->organization_id,
            'يجب ألا يُسند المشروع لمؤسسة المستخدم الآخر'
        );
    }

    public function test_created_project_sets_created_by_to_authenticated_user(): void
    {
        $payload = [
            'name' => 'مشروع لفحص created_by',
            'type' => 'development',
            'priority' => 'low',
            'start_date' => '2026-07-01',
            'end_date' => '2026-12-31',
        ];

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/projects', $payload);

        $response->assertStatus(201);

        $projectId = $response->json('project.id');
        $this->assertNotNull($projectId);

        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'created_by' => $this->superAdmin->id,
            'organization_id' => $this->org->id,
        ]);
    }
}
