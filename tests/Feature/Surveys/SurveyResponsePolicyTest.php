<?php

namespace Tests\Feature\Surveys;

use App\Modules\Core\Authorization\AccessDecision;
use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\ScopedRole;
use App\Modules\Core\Models\ScopedRoleDefinition;
use App\Modules\Core\Models\ScopeType;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyResponse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SurveyResponsePolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bridge the engine to a single capability.
     *
     * Deviation from the brief: AccessDecision reads ScopedRoleDefinition.permissions
     * (and is_admin_role), NOT the user's flat Spatie permissions. Granting a Spatie
     * permission named after the capability string therefore makes
     * AccessDecision::can($user, Capability::SURVEYS_REVIEW_RESPONSES, $survey) return
     * FALSE both today and after Task 3. The engine's actual mechanism is a scoped
     * role whose definition's `permissions` array contains the capability — so we
     * create that definition on scope=organization and grant it here.
     *
     * Effect on RED: the current policy checks `$user->can('review_survey_responses')`
     * (legacy flat string) first; the scoped role grants the new capability string
     * only, so under today's flat-perm policy the positive test reaches the perm
     * check and fails it (current returns FALSE, test expects TRUE → RED). The
     * other three (lacks / cross_org / super_admin) already pass today: lacks and
     * cross_org already return FALSE under the legacy policy; super_admin gets the
     * legacy perm via RolesAndPermissionsSeeder so the current policy correctly
     * returns TRUE for a same-org target. After Task 3 rewires the policy to
     * AccessDecision::can, all four assertions must continue to hold via the engine.
     */
    private function makeUserWithReviewCap(string $orgId): User
    {
        $user = User::factory()->create(['organization_id' => $orgId]);

        // Create the scope type + a scoped-role definition whose `permissions`
        // includes the new capability. This is the engine-readable grant.
        $scopeType = ScopeType::firstOrCreate(
            ['key' => ScopedRole::SCOPE_ORGANIZATION],
            [
                'label_ar' => 'Organization',
                'label_en' => 'Organization',
                'model_class' => Organization::class,
                'supports_hierarchy' => false,
                'is_active' => true,
                'sort_order' => 0,
            ]
        );

        // LR-103: scoped_role_definitions has legacy NOT NULL columns
        // (name, display_name, scope_type) with no defaults. Force-fill them.
        $existingId = DB::table('scoped_role_definitions')
            ->where('name', 'survey_reviewer')
            ->where('scope_type', ScopedRole::SCOPE_ORGANIZATION)
            ->value('id');

        $attributes = [
            'scope_type_id' => $scopeType->id,
            'role_key' => 'survey_reviewer',
            'display_name' => 'survey_reviewer',
            'label_ar' => 'مراجع الاستبيانات',
            'label_en' => 'Survey Reviewer',
            // DB query-builder does not apply model casts; json-encode manually.
            'permissions' => json_encode($this->expandFlags([Capability::SURVEYS_REVIEW_RESPONSES], [
                'can_edit' => false, 'can_delete' => false, 'can_view_all' => true, 'can_manage_members' => false,
            ])),
            'is_admin_role' => false,
            'is_active' => true,
            'sort_order' => 0,
            'updated_at' => now(),
        ];

        if ($existingId) {
            DB::table('scoped_role_definitions')->where('id', $existingId)->update($attributes);
            $definitionId = $existingId;
        } else {
            $definitionId = DB::table('scoped_role_definitions')->insertGetId($attributes + [
                'name' => 'survey_reviewer',
                'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
                'created_at' => now(),
            ]);
        }

        $definition = ScopedRoleDefinition::find($definitionId);

        // Grant the scoped role on the user's organization.
        // The model_has_scoped_roles table stores `role` (string) + `scope_type`
        // + `scope_id` only — no role_definition_id column.
        ScopedRole::create([
            'user_id' => $user->id,
            'role' => 'survey_reviewer',
            'scope_type' => ScopedRole::SCOPE_ORGANIZATION,
            'scope_id' => (int) $orgId,
        ]);

        // Drop any cached decisions for this user.
        AccessDecision::flushUserCache((int) $user->id);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Needed for the super_admin role + the role-creation paths below.
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_review_returns_true_for_same_org_with_engine_capability(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeUserWithReviewCap($org->id);

        $survey = Survey::factory()->create(['organization_id' => $org->id]);
        $response = SurveyResponse::factory()->create(['survey_id' => $survey->id]);

        $this->assertTrue($user->can('review', $response));
    }

    public function test_review_returns_false_when_user_lacks_capability(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        // No capability granted.

        $survey = Survey::factory()->create(['organization_id' => $org->id]);
        $response = SurveyResponse::factory()->create(['survey_id' => $survey->id]);

        $this->assertFalse($user->can('review', $response));
    }

    public function test_review_returns_false_for_cross_org_user(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = $this->makeUserWithReviewCap($orgA->id);

        $survey = Survey::factory()->create(['organization_id' => $orgB->id]);
        $response = SurveyResponse::factory()->create(['survey_id' => $survey->id]);

        $this->assertFalse($user->can('review', $response));
    }

    public function test_super_admin_bypasses_org_check(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $super = User::factory()->create(['organization_id' => $orgA->id]);
        $superRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $super->assignRole($superRole);

        $survey = Survey::factory()->create(['organization_id' => $orgB->id]);
        $response = SurveyResponse::factory()->create(['survey_id' => $survey->id]);

        $this->assertTrue($super->can('review', $response));
    }

    /**
     * Expand legacy granular flags into the equivalent explicit permissions
     * (Phase 3, ADR-UNIFIED-ROLE-ACCESS — the flag columns were dropped from
     * scoped_role_definitions; the engine now reads permissions[] only).
     *
     * @param  array<int, string>  $permissions
     * @param  array<string, bool>  $flags
     * @return array<int, string>
     */
    private function expandFlags(array $permissions, array $flags): array
    {
        $byAction = fn (array $actions): array => array_values(array_filter(
            Capability::all(),
            function (string $c) use ($actions) {
                $a = str_contains($c, '.') ? substr($c, strrpos($c, '.') + 1) : $c;

                return in_array($a, $actions, true);
            }
        ));
        if (! empty($flags['can_edit'])) {
            $permissions = array_merge($permissions, $byAction(['edit', 'update']));
        }
        if (! empty($flags['can_delete'])) {
            $permissions = array_merge($permissions, $byAction(['delete', 'remove']));
        }
        if (! empty($flags['can_view_all'])) {
            $permissions = array_merge($permissions, $byAction(['view', 'view_all', 'view_reports']));
        }
        if (! empty($flags['can_manage_members'])) {
            $permissions = array_merge($permissions, $byAction(['manage_members', 'assign_roles']));
        }
        if (! empty($flags['can_view_confidential'])) {
            $permissions[] = Capability::OVR_VIEW_CONFIDENTIAL;
        }

        return array_values(array_unique($permissions));
    }
}
