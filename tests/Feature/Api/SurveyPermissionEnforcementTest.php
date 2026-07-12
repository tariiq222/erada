<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Surveys\Models\Survey;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P0-11 — فرض صلاحيات Surveys (التوزيع العمودي داخل المنظمة)
 *
 * الصلاحيات: view_surveys / create_surveys / edit_surveys / delete_surveys / view_survey_responses
 * يجب أن تُفرض على الـ API، لا أن يكتفي بعزل المنظمة فقط.
 */
class SurveyPermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected Survey $survey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->survey = Survey::factory()->create([
            'organization_id' => $this->org->id,
        ]);
    }

    private function userWith(?string $role): User
    {
        $user = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_active' => true,
        ]);
        if ($role) {
            $this->assignCanonicalRole($user, $role);
        }

        return $user;
    }

    /** member بلا صلاحية create_surveys يجب ألا ينشئ استبياناً. */
    public function test_member_cannot_create_survey(): void
    {
        $member = $this->userWith('member');

        $this->actingAs($member, 'sanctum')
            ->postJson('/api/surveys', [
                'title' => 'استبيان غير مصرح',
                'description' => 'x',
            ])
            ->assertStatus(403);
    }

    /** viewer بلا صلاحية delete_surveys يجب ألا يحذف استبياناً. */
    public function test_viewer_cannot_delete_survey(): void
    {
        $viewer = $this->userWith('viewer');

        $this->actingAs($viewer, 'sanctum')
            ->deleteJson("/api/surveys/{$this->survey->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('surveys', ['id' => $this->survey->id, 'deleted_at' => null]);
    }

    /** member بلا صلاحية view_survey_responses يجب ألا يقرأ الإجابات. */
    public function test_member_cannot_view_survey_responses(): void
    {
        $member = $this->userWith('member');

        $this->actingAs($member, 'sanctum')
            ->getJson("/api/surveys/{$this->survey->id}/responses")
            ->assertStatus(403);
    }

    /** super_admin يتجاوز كل شيء (ضمان عدم الكسر). */
    public function test_super_admin_can_create_survey(): void
    {
        $admin = $this->userWith('super_admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/surveys', [
                'title' => 'استبيان مصرح',
                'description' => 'x',
                'type' => 'initial',
            ])
            ->assertStatus(201);
    }
}
