<?php

namespace Tests\Feature\OVR;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\OVR\Models\ReportableType;
use App\Modules\OVR\Models\ReportComment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentReportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Organization $organization;

    protected Department $department;

    protected IncidentType $incidentType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->organization = Organization::factory()->create();
        $this->department = Department::factory()->create();

        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->user->assignRole('super_admin');

        $this->incidentType = IncidentType::create([
            'name' => 'Medical Error',
            'name_ar' => 'خطأ طبي',
            'is_active' => true,
        ]);
    }

    private function incidentData(array $override = []): array
    {
        return array_merge([
            'incident_datetime' => now()->format('Y-m-d H:i:s'),
            'is_patient_related' => true,
            'patient_name' => 'Test Patient',
            'patient_file_number' => 'PF-12345',
            'patient_gender' => 'male',
            'patient_dob' => '1990-01-01',
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'Test incident description',
            'actions_taken' => 'Initial action taken',
            'contributing_factors' => ['factor1', 'factor2'],
            'immediate_action_required' => true,
            'severity_level' => SeverityLevel::High->value,
            'is_confidential' => false,
        ], $override);
    }

    /**
     * اختبار عرض قائمة تقارير الحوادث
     */
    public function test_can_list_incident_reports(): void
    {
        IncidentReport::create($this->incidentData([
            'organization_id' => $this->organization->id,
            'reporter_id' => $this->user->id,
            'reporter_name' => $this->user->name,
            'reporter_email' => $this->user->email,
            'reporter_department_id' => $this->department->id,
            'status' => ReportStatus::Draft,
        ]));

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/ovr/incidents');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
            ]);
    }

    /**
     * اختبار إنشاء تقرير حادثة
     */
    public function test_can_create_incident_report(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/ovr/incidents', $this->incidentData());

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'report_number',
                ],
            ]);

        $this->assertDatabaseHas('ovr_incident_reports', [
            'reporter_id' => $this->user->id,
            'severity_level' => SeverityLevel::High->value,
        ]);
    }

    /**
     * اختبار عرض تقرير حادثة واحد
     */
    public function test_can_view_incident_report(): void
    {
        $report = IncidentReport::create($this->incidentData([
            'organization_id' => $this->organization->id,
            'reporter_id' => $this->user->id,
            'reporter_name' => $this->user->name,
            'reporter_email' => $this->user->email,
            'reporter_department_id' => $this->department->id,
            'status' => ReportStatus::Draft,
        ]));

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/ovr/incidents/{$report->report_number}");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'id' => $report->id,
            ]);
    }

    /**
     * اختبار تحديث تقرير حادثة
     */
    public function test_can_update_incident_report(): void
    {
        $report = IncidentReport::create($this->incidentData([
            'organization_id' => $this->organization->id,
            'reporter_id' => $this->user->id,
            'reporter_name' => $this->user->name,
            'reporter_email' => $this->user->email,
            'reporter_department_id' => $this->department->id,
            'status' => ReportStatus::Draft,
        ]));

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/ovr/incidents/{$report->report_number}", [
                'incident_description' => 'Updated description',
                'severity_level' => SeverityLevel::Critical->value,
                'incident_datetime' => now()->format('Y-m-d H:i:s'),
                'is_patient_related' => true,
                'patient_name' => 'Test Patient',
                'informed_authority' => false,
                'incident_type_id' => $this->incidentType->id,
                'immediate_action_required' => true,
                'is_confidential' => false,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('ovr_incident_reports', [
            'id' => $report->id,
            'incident_description' => 'Updated description',
            'severity_level' => SeverityLevel::Critical->value,
        ]);
    }

    /**
     * اختبار حذف تقرير حادثة
     */
    public function test_can_delete_incident_report(): void
    {
        $report = IncidentReport::create($this->incidentData([
            'organization_id' => $this->organization->id,
            'reporter_id' => $this->user->id,
            'reporter_name' => $this->user->name,
            'reporter_email' => $this->user->email,
            'reporter_department_id' => $this->department->id,
            'status' => ReportStatus::Draft,
        ]));

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/ovr/incidents/{$report->report_number}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('ovr_incident_reports', [
            'id' => $report->id,
        ]);
    }

    /**
     * اختبار إرسال تقرير مسودة
     */
    public function test_can_submit_draft_report(): void
    {
        $report = IncidentReport::create($this->incidentData([
            'organization_id' => $this->organization->id,
            'reporter_id' => $this->user->id,
            'reporter_name' => $this->user->name,
            'reporter_email' => $this->user->email,
            'reporter_department_id' => $this->department->id,
            'status' => ReportStatus::Draft,
        ]));

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/ovr/incidents/{$report->report_number}/submit");

        $response->assertStatus(200);

        $this->assertDatabaseHas('ovr_incident_reports', [
            'id' => $report->id,
            'status' => ReportStatus::New->value,
        ]);
    }

    /**
     * اختبار تغيير حالة التقرير
     */
    public function test_can_change_status(): void
    {
        $report = IncidentReport::create($this->incidentData([
            'organization_id' => $this->organization->id,
            'reporter_id' => $this->user->id,
            'reporter_name' => $this->user->name,
            'reporter_email' => $this->user->email,
            'reporter_department_id' => $this->department->id,
            'status' => ReportStatus::New,
        ]));

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", [
                'status' => ReportStatus::UnderReview->value,
                'reason' => 'Started review',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('ovr_incident_reports', [
            'id' => $report->id,
            'status' => ReportStatus::UnderReview->value,
        ]);
    }

    /**
     * اختبار رفض انتقال حالة غير مسموح
     */
    public function test_cannot_transition_invalid_status(): void
    {
        $report = IncidentReport::create($this->incidentData([
            'organization_id' => $this->organization->id,
            'reporter_id' => $this->user->id,
            'reporter_name' => $this->user->name,
            'reporter_email' => $this->user->email,
            'reporter_department_id' => $this->department->id,
            'status' => ReportStatus::Draft,
        ]));

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", [
                'status' => ReportStatus::Closed->value,
                'reason' => 'Invalid transition',
            ]);

        $response->assertStatus(422);
    }

    /**
     * اختبار عرض تعليقات التقرير
     */
    public function test_can_list_comments(): void
    {
        $report = IncidentReport::create($this->incidentData([
            'organization_id' => $this->organization->id,
            'reporter_id' => $this->user->id,
            'reporter_name' => $this->user->name,
            'reporter_email' => $this->user->email,
            'reporter_department_id' => $this->department->id,
            'status' => ReportStatus::Draft,
        ]));

        ReportComment::create([
            'report_id' => $report->id,
            'user_id' => $this->user->id,
            'author_name' => $this->user->name,
            'text' => 'Test comment',
            'is_internal' => false,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/ovr/incidents/{$report->report_number}/comments");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
            ]);
    }

    /**
     * اختبار إضافة تعليق على التقرير
     */
    public function test_can_add_comment(): void
    {
        $report = IncidentReport::create($this->incidentData([
            'organization_id' => $this->organization->id,
            'reporter_id' => $this->user->id,
            'reporter_name' => $this->user->name,
            'reporter_email' => $this->user->email,
            'reporter_department_id' => $this->department->id,
            'status' => ReportStatus::Draft,
        ]));

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/ovr/incidents/{$report->report_number}/comments", [
                'text' => 'New comment text',
                'is_internal' => false,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('ovr_report_comments', [
            'report_id' => $report->id,
            'text' => 'New comment text',
        ]);
    }

    /**
     * اختبار إحصائيات التقارير
     */
    public function test_stats_endpoint_returns_data(): void
    {
        IncidentReport::create($this->incidentData([
            'organization_id' => $this->organization->id,
            'reporter_id' => $this->user->id,
            'reporter_name' => $this->user->name,
            'reporter_email' => $this->user->email,
            'reporter_department_id' => $this->department->id,
            'status' => ReportStatus::Draft,
            'severity_level' => SeverityLevel::High,
        ]));

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/ovr/incidents/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'total',
                'by_status',
                'by_severity',
                'patient_related',
                'informed_authority',
                'overdue',
                'avg_resolution_hours',
                'immediate_action_required',
                'confidential',
                'rates' => [
                    'patient_related',
                    'informed_authority',
                    'immediate_action_required',
                    'confidential',
                ],
                'breakdowns' => [
                    'incident_type',
                    'reportable_type',
                    'department',
                    'patient_gender',
                    'contributing_factor',
                    'monthly_trend',
                ],
                'period',
            ]);
    }

    public function test_stats_endpoint_supports_enhanced_filters_and_breakdowns(): void
    {
        $otherDepartment = Department::factory()->create();
        $otherType = IncidentType::create([
            'name' => 'Workplace Injury',
            'name_ar' => 'إصابة عمل',
            'is_active' => true,
        ]);
        $matchingReportableType = ReportableType::create([
            'incident_type_id' => $this->incidentType->id,
            'name' => 'Medication Error',
            'name_ar' => 'خطأ دوائي',
        ]);
        $otherReportableType = ReportableType::create([
            'incident_type_id' => $otherType->id,
            'name' => 'Slip',
            'name_ar' => 'انزلاق',
        ]);

        $matchingReport = IncidentReport::create($this->incidentData([
            'organization_id' => $this->organization->id,
            'reporter_id' => $this->user->id,
            'reporter_name' => $this->user->name,
            'reporter_email' => $this->user->email,
            'reporter_department_id' => $this->department->id,
            'incident_datetime' => '2026-05-10 08:30:00',
            'status' => ReportStatus::New->value,
            'severity_level' => SeverityLevel::Critical->value,
            'reportable_incident_type_id' => $matchingReportableType->id,
            'is_patient_related' => true,
            'patient_gender' => 'male',
            'informed_authority' => true,
            'immediate_action_required' => true,
            'is_confidential' => true,
            'contributing_factors' => ['communication', 'training'],
        ]));
        $matchingReport->forceFill([
            'created_at' => '2026-06-15 10:00:00',
            'updated_at' => '2026-06-15 10:00:00',
        ])->save();

        $nonMatchingReport = IncidentReport::create($this->incidentData([
            'organization_id' => $this->organization->id,
            'reporter_id' => $this->user->id,
            'reporter_name' => $this->user->name,
            'reporter_email' => $this->user->email,
            'reporter_department_id' => $otherDepartment->id,
            'incident_type_id' => $otherType->id,
            'reportable_incident_type_id' => $otherReportableType->id,
            'status' => ReportStatus::Resolved->value,
            'severity_level' => SeverityLevel::Low->value,
            'is_patient_related' => false,
            'patient_gender' => 'female',
            'informed_authority' => false,
            'immediate_action_required' => false,
            'is_confidential' => false,
            'contributing_factors' => ['staffing'],
        ]));
        $nonMatchingReport->forceFill([
            'created_at' => '2026-06-16 10:00:00',
            'updated_at' => '2026-06-16 10:00:00',
        ])->save();

        $outsideDateReport = IncidentReport::create($this->incidentData([
            'organization_id' => $this->organization->id,
            'reporter_id' => $this->user->id,
            'reporter_name' => $this->user->name,
            'reporter_email' => $this->user->email,
            'reporter_department_id' => $this->department->id,
            'status' => ReportStatus::New->value,
            'severity_level' => SeverityLevel::Critical->value,
            'reportable_incident_type_id' => $matchingReportableType->id,
            'is_patient_related' => true,
            'patient_gender' => 'male',
            'informed_authority' => true,
            'immediate_action_required' => true,
            'is_confidential' => true,
            'contributing_factors' => ['communication'],
        ]));
        $outsideDateReport->forceFill([
            'created_at' => '2026-05-30 10:00:00',
            'updated_at' => '2026-05-30 10:00:00',
        ])->save();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/ovr/incidents/stats?'.http_build_query([
                'from' => '2026-06-01',
                'to' => '2026-06-30',
                'status' => ReportStatus::New->value,
                'severity' => SeverityLevel::Critical->value,
                'incident_type_id' => $this->incidentType->id,
                'reportable_incident_type_id' => $matchingReportableType->id,
                'is_patient_related' => '1',
                'patient_gender' => 'male',
                'informed_authority' => '1',
                'immediate_action_required' => '1',
                'is_confidential' => '1',
                'reporter_department_id' => $this->department->id,
                'contributing_factor' => 'communication',
            ]));

        $response->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('patient_related', 1)
            ->assertJsonPath('informed_authority', 1)
            ->assertJsonPath('immediate_action_required', 1)
            ->assertJsonPath('confidential', 1)
            ->assertJsonPath('by_status.'.ReportStatus::New->value, 1)
            ->assertJsonPath('by_severity.'.SeverityLevel::Critical->value, 1)
            ->assertJsonPath('rates.patient_related', 100)
            ->assertJsonPath('rates.informed_authority', 100)
            ->assertJsonPath('rates.immediate_action_required', 100)
            ->assertJsonPath('rates.confidential', 100)
            ->assertJsonPath('breakdowns.incident_type.0.id', $this->incidentType->id)
            ->assertJsonPath('breakdowns.incident_type.0.name', 'Medical Error')
            ->assertJsonPath('breakdowns.incident_type.0.name_ar', 'خطأ طبي')
            ->assertJsonPath('breakdowns.incident_type.0.count', 1)
            ->assertJsonPath('breakdowns.reportable_type.0.id', $matchingReportableType->id)
            ->assertJsonPath('breakdowns.reportable_type.0.count', 1)
            ->assertJsonPath('breakdowns.department.0.id', (string) $this->department->id)
            ->assertJsonPath('breakdowns.department.0.count', 1)
            ->assertJsonPath('breakdowns.patient_gender.0.gender', 'male')
            ->assertJsonPath('breakdowns.patient_gender.0.count', 1)
            ->assertJsonPath('breakdowns.monthly_trend.0.month', '2026-06')
            ->assertJsonPath('breakdowns.monthly_trend.0.count', 1);

        $factors = collect($response->json('breakdowns.contributing_factor'))->keyBy('factor');
        $this->assertSame(1, $factors->get('communication')['count']);
        $this->assertSame(1, $factors->get('training')['count']);
    }

    /**
     * اختبار أن أي موظف مسجل (دور viewer فقط) يستطيع إنشاء تقرير حادثة
     */
    public function test_user_with_only_viewer_role_can_create_incident_report(): void
    {
        $viewer = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $viewer->assignRole('viewer');

        $response = $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/ovr/incidents', $this->incidentData());

        $response->assertStatus(201);

        $this->assertDatabaseHas('ovr_incident_reports', [
            'reporter_id' => $viewer->id,
        ]);
    }

    /**
     * اختبار أن بيانات المبلّغ تُؤخذ من المستخدم الموثّق وتتجاهل أي قيم مرسلة في الطلب
     */
    public function test_reporter_snapshot_is_taken_from_authenticated_user(): void
    {
        $reporter = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'job_title' => 'Staff Nurse',
            'extension' => '4321',
            'is_active' => true,
        ]);
        // The viewer role carries the org-scoped reporting baseline (ovr.create),
        // so creation is authorized. The reporter snapshot fields below are still
        // ignored and taken from the authenticated user (anti-spoofing).
        $reporter->assignRole('viewer');

        $response = $this->actingAs($reporter, 'sanctum')
            ->postJson('/api/ovr/incidents', $this->incidentData([
                'reporter_id' => $this->user->id,
                'reporter_name' => 'Spoofed Name',
                'reporter_email' => 'spoofed@example.com',
                'reporter_job_title' => 'Spoofed Title',
                'reporter_extension' => '9999',
            ]));

        $response->assertStatus(201);

        $this->assertDatabaseHas('ovr_incident_reports', [
            'reporter_id' => $reporter->id,
            'reporter_name' => $reporter->name,
            'reporter_email' => $reporter->email,
            'reporter_job_title' => 'Staff Nurse',
            'reporter_extension' => '4321',
            'reporter_department_id' => $this->department->id,
        ]);
    }

    /**
     * اختبار رفض تاريخ حادثة مستقبلي
     */
    public function test_cannot_create_report_with_future_incident_datetime(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/ovr/incidents', $this->incidentData([
                'incident_datetime' => now()->addDay()->format('Y-m-d H:i:s'),
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['incident_datetime']);
    }

    /**
     * اختبار رفض نوع فرعي لا يتبع نوع الحادثة المختار
     */
    public function test_cannot_create_report_with_reportable_type_from_different_incident_type(): void
    {
        $otherType = IncidentType::create([
            'name' => 'Other Type',
            'name_ar' => 'نوع آخر',
            'is_active' => true,
        ]);

        $foreignSubType = ReportableType::create([
            'incident_type_id' => $otherType->id,
            'name' => 'Foreign Sub Type',
            'name_ar' => 'نوع فرعي غريب',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/ovr/incidents', $this->incidentData([
                'incident_type_id' => $this->incidentType->id,
                'reportable_incident_type_id' => $foreignSubType->id,
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reportable_incident_type_id']);
    }

    /**
     * اختبار وجوب النوع الفرعي عندما يتطلبه نوع الحادثة
     */
    public function test_reportable_type_is_required_when_incident_type_requires_it(): void
    {
        $mandatoryType = IncidentType::create([
            'name' => 'Reportable Incident',
            'name_ar' => 'حادثة إبلاغ إلزامي',
            'is_active' => true,
            'requires_reportable_type' => true,
        ]);

        ReportableType::create([
            'incident_type_id' => $mandatoryType->id,
            'name' => 'Medication Error',
            'name_ar' => 'خطأ دوائي',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/ovr/incidents', $this->incidentData([
                'incident_type_id' => $mandatoryType->id,
                'reportable_incident_type_id' => null,
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reportable_incident_type_id']);

        // ومع تحديد النوع الفرعي الصحيح يُقبل الطلب
        $validSubType = ReportableType::where('incident_type_id', $mandatoryType->id)->first();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/ovr/incidents', $this->incidentData([
                'incident_type_id' => $mandatoryType->id,
                'reportable_incident_type_id' => $validSubType->id,
            ]));

        $response->assertStatus(201);
    }
}
