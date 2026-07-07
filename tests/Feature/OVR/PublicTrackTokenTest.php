<?php

namespace Tests\Feature\OVR;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\OVR\Enums\ReportStatus;
use App\Modules\OVR\Enums\SeverityLevel;
use App\Modules\OVR\Models\IncidentReport;
use App\Modules\OVR\Models\IncidentType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PublicTrackTokenTest — P0 #4 audit fix.
 *
 * The public /api/ovr/track endpoint now keys on a per-report random
 * `tracking_token` (64-char URL-safe random) rather than the enumerable
 * `report_number` (e.g. OVR-2026-0001). This closes the enumeration leak
 * where a reporter who guessed adjacent numbers could peek at adjacent
 * reports' status. Migration
 * 2026_07_07_000005_add_tracking_token_to_incident_reports ships the
 * column with backfill; this test pins the new public endpoint contract.
 *
 * Coverage:
 *  1. test_public_track_with_valid_tracking_token_returns_data — happy path
 *     with the token in the URL returns 200 + the public status payload.
 *  2. test_public_track_with_invalid_tracking_token_returns_404 — wrong/missing
 *     token returns 404 (no record leaks via the public endpoint).
 *  3. test_public_track_with_report_number_returns_404 — regression guard.
 *     The OLD report_number path is closed; passing OVR-2026-NNNN must 404
 *     and NOT resolve the public payload, proving the route fully migrated.
 */
class PublicTrackTokenTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Department $dept;

    private User $user;

    private IncidentType $incidentType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);

        $this->user = User::factory()->create([
            'organization_id' => $this->org->id,
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->user->assignRole('super_admin');

        $this->incidentType = IncidentType::create([
            'name' => 'Med Error',
            'name_ar' => 'خطأ طبي',
            'is_active' => true,
        ]);
    }

    private function makeReport(array $overrides = []): IncidentReport
    {
        return IncidentReport::create(array_merge([
            'organization_id' => $this->org->id,
            'reporter_id' => $this->user->id,
            'reporter_name' => $this->user->name,
            'reporter_email' => $this->user->email,
            'reporter_department_id' => $this->dept->id,
            'incident_datetime' => now(),
            'is_patient_related' => false,
            'informed_authority' => false,
            'incident_type_id' => $this->incidentType->id,
            'incident_description' => 'public track fixture',
            'immediate_action_required' => false,
            'severity_level' => SeverityLevel::Medium,
            'status' => ReportStatus::UnderReview,
            'is_confidential' => false,
        ], $overrides));
    }

    /**
     * Happy path: a valid tracking_token in the URL returns the public
     * status payload. The token is the access credential — the
     * response itself does NOT echo the token (only the human-facing
     * report_number is returned for display in the reporter-tracking UI).
     */
    public function test_public_track_with_valid_tracking_token_returns_data(): void
    {
        $report = $this->makeReport(['status' => ReportStatus::Resolved]);
        $token = Str::random(64);
        $report->forceFill(['tracking_token' => $token])->save();

        $response = $this->getJson("/api/ovr/track/{$token}");

        $response->assertStatus(200)
            ->assertJsonPath('data.report_number', $report->report_number)
            ->assertJsonPath('data.status', ReportStatus::Resolved->value)
            ->assertJsonMissingPath('data.tracking_token');
    }

    /**
     * A wrong/random tracking_token must 404 with no record leak. The
     * controller's where('tracking_token', ...) returns null and the
     * report_not_found envelope is returned; no PII or status data leaks.
     */
    public function test_public_track_with_invalid_tracking_token_returns_404(): void
    {
        $report = $this->makeReport(['status' => ReportStatus::UnderReview]);
        $realToken = Str::random(64);
        $report->forceFill(['tracking_token' => $realToken])->save();

        $response = $this->getJson('/api/ovr/track/'.Str::random(64));

        $response->assertStatus(404)
            ->assertJsonMissingPath('data.report_number')
            ->assertJsonMissingPath('data.status');

        // The fixture row still exists — the 404 is endpoint behavior, not
        // a record deletion.
        $this->assertDatabaseHas('ovr_incident_reports', [
            'id' => $report->id,
            'tracking_token' => $realToken,
        ]);
    }

    /**
     * Regression guard for the migration: the OLD path of passing a
     * `report_number` (e.g. OVR-2026-0001) in the URL MUST return 404.
     * This proves the route fully migrated off report_number and that no
     * legacy enumeration vector is reachable.
     */
    public function test_public_track_with_report_number_returns_404(): void
    {
        $report = $this->makeReport(['status' => ReportStatus::UnderReview]);

        $response = $this->getJson("/api/ovr/track/{$report->report_number}");

        $response->assertStatus(404)
            ->assertJsonMissingPath('data.report_number')
            ->assertJsonMissingPath('data.status');

        $this->assertDatabaseHas('ovr_incident_reports', [
            'id' => $report->id,
            'report_number' => $report->report_number,
        ]);
    }
}
