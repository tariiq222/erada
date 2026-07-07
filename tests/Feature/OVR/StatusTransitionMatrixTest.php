<?php

namespace Tests\Feature\OVR;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use App\Modules\OVR\Models\StatusHistory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * StatusTransitionMatrixTest — exhaustive parity test for the 9-state ReportStatus
 * enum and the controller's PATCH /api/ovr/incidents/{report}/status endpoint.
 *
 * Drives the full ReportStatus::allowedTransitions() matrix through the HTTP
 * surface to confirm:
 *   - every (from, to) IN the matrix → 200 + DB row updates + StatusHistory row
 *     written with correct from_status / to_status / changed_by
 *   - a representative sample of (from, to) NOT in the matrix → 422 (including
 *     the most damaging "skip a step" cases that the audit called out)
 *
 * The data provider derives the (from, allowed_to) pairs directly from
 * ReportStatus so the test stays in sync with the enum — no hard-coded list of
 * allowed transitions to drift.
 */
class StatusTransitionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Department $dept;

    private User $reporter;

    private User $actor;

    private IncidentType $incidentType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);

        $this->reporter = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);

        // super_admin bypasses every authorization layer, so each cell can
        // exercise the state machine without us hand-rolling engine grants.
        $this->actor = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->actor->assignRole('super_admin');

        $this->incidentType = IncidentType::create([
            'name' => 'Medical Error',
            'name_ar' => 'خطأ طبي',
            'is_active' => true,
        ]);
    }

    /**
     * Build a report preloaded in a specific status. Caller may override
     * any column (e.g. resolved_at for Resolved → Closed paths).
     */
    private function makeReportInStatus(ReportStatus $status, array $overrides = []): IncidentReport
    {
        $base = [
            'organization_id' => $this->org->id,
            'reporter_id' => $this->reporter->id,
            'reporter_name' => $this->reporter->name,
            'reporter_email' => $this->reporter->email,
            'reporter_department_id' => $this->dept->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => "transition fixture from {$status->value}",
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::Medium,
            'status' => $status,
            'is_confidential' => false,
        ];

        if ($status === ReportStatus::Resolved) {
            $base['resolved_at'] = now();
        }
        if ($status === ReportStatus::Closed) {
            $base['resolved_at'] = now()->subHour();
            $base['closed_at'] = now();
            $base['closed_by'] = $this->actor->id;
            $base['closure_reason'] = 'pre-existing closure (fixture)';
        }
        if ($status === ReportStatus::Archived) {
            $base['resolved_at'] = now()->subDay();
            $base['closed_at'] = now()->subDay();
            $base['closed_by'] = $this->actor->id;
            $base['closure_reason'] = 'pre-existing closure (archived fixture)';
        }

        return IncidentReport::create(array_merge($base, $overrides));
    }

    /**
     * Walk the full ReportStatus transition matrix. Each (from, to) cell that
     * IS allowed by the enum must round-trip through the API; each cell that is
     * NOT allowed must 422. The data provider derives the (from, allowed_to)
     * pairs from the enum itself so it cannot drift out of sync.
     *
     * @return iterable<string, array{ReportStatus, ReportStatus, bool}>
     */
    public static function transitionMatrixProvider(): iterable
    {
        $all = ReportStatus::cases();

        // Allowed transitions: every (from, to) the enum admits.
        foreach ($all as $from) {
            foreach ($from->allowedTransitions() as $to) {
                yield "allowed_{$from->value}_to_{$to->value}" => [$from, $to, true];
            }
        }

        // Critical "skipping a step" invalid transitions — the audit called
        // these out specifically. They must all 422.
        $forbiddenSamples = [
            [ReportStatus::Draft, ReportStatus::UnderReview],
            [ReportStatus::Draft, ReportStatus::Resolved],
            [ReportStatus::Draft, ReportStatus::Closed],
            [ReportStatus::Draft, ReportStatus::Archived],
            [ReportStatus::New, ReportStatus::Closed],
            [ReportStatus::New, ReportStatus::Archived],
            [ReportStatus::New, ReportStatus::PendingInfo],
            [ReportStatus::Resolved, ReportStatus::InProgress],
            [ReportStatus::Resolved, ReportStatus::New],
            [ReportStatus::Closed, ReportStatus::UnderReview],
            [ReportStatus::Closed, ReportStatus::Rejected],
            [ReportStatus::Archived, ReportStatus::UnderReview],
            [ReportStatus::Archived, ReportStatus::Resolved],
            [ReportStatus::Rejected, ReportStatus::Closed],
            [ReportStatus::Rejected, ReportStatus::Archived],
        ];
        foreach ($forbiddenSamples as [$from, $to]) {
            yield "forbidden_{$from->value}_to_{$to->value}" => [$from, $to, false];
        }
    }

    #[DataProvider('transitionMatrixProvider')]
    public function test_transition_matrix_cell(ReportStatus $from, ReportStatus $to, bool $allowed): void
    {
        $report = $this->makeReportInStatus($from);

        $payload = ['status' => $to->value];

        // The closure_reason guard is enforced for Closed regardless of the
        // state-machine outcome; include a valid reason so the validator
        // doesn't mask the 200 we're trying to assert.
        if ($to === ReportStatus::Closed) {
            $payload['closure_reason'] = 'Closed during transition matrix test';
        }

        $response = $this->actingAs($this->actor, 'sanctum')
            ->patchJson("/api/ovr/incidents/{$report->report_number}/status", $payload);

        if ($allowed) {
            $response->assertStatus(200);
            $this->assertDatabaseHas('ovr_incident_reports', [
                'id' => $report->id,
                'status' => $to->value,
            ]);

            $history = StatusHistory::query()
                ->where('report_id', $report->id)
                ->where('from_status', $from->value)
                ->where('to_status', $to->value)
                ->first();

            $this->assertNotNull(
                $history,
                "StatusHistory row missing for allowed {$from->value} → {$to->value}"
            );
            $this->assertSame(
                $this->actor->id,
                $history->changed_by,
                "StatusHistory.changed_by wrong for {$from->value} → {$to->value}"
            );
        } else {
            $response->assertStatus(422);
            $this->assertDatabaseHas('ovr_incident_reports', [
                'id' => $report->id,
                'status' => $from->value,
            ]);
            $this->assertDatabaseMissing('ovr_status_history', [
                'report_id' => $report->id,
                'to_status' => $to->value,
            ]);
        }
    }
}
