<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Models\SurveyField;
use App\Modules\Surveys\Models\SurveyInvitation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurveyFieldAndInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Department $department;

    protected Survey $survey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Skip-Csrf' => '1']);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->department = Department::factory()->create();

        $this->user = User::factory()->create([
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalSuperAdmin($this->user);

        // إنشاء استبيان في حالة draft
        $this->survey = Survey::factory()->create([
            'organization_id' => 1,
            'created_by' => $this->user->id,
            'status' => 'draft',
        ]);
    }

    // ========== مساعد لإنشاء الدعوة مباشرة ==========

    private function createInvitation(array $overrides = []): SurveyInvitation
    {
        return $this->survey->invitations()->create(array_merge([
            'email' => 'test@example.com',
            'status' => 'active',
            'max_uses' => 1,
            'used_count' => 0,
            'reminder_count' => 0,
            'created_by' => $this->user->id,
        ], $overrides));
    }

    // ========== SurveyField - index ==========

    public function test_can_list_survey_fields(): void
    {
        SurveyField::factory()->count(3)->create([
            'survey_id' => $this->survey->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/surveys/{$this->survey->id}/fields");

        $response->assertOk()
            ->assertJsonStructure(['data']);

        $this->assertCount(3, $response->json('data'));
    }

    public function test_list_fields_requires_auth(): void
    {
        $this->getJson("/api/surveys/{$this->survey->id}/fields")
            ->assertUnauthorized();
    }

    // ========== SurveyField - store ==========

    public function test_can_create_survey_field(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/surveys/{$this->survey->id}/fields", [
                'name' => 'Test Field',
                'label' => 'Test Field Label',
                'field_key' => 'test_field_key',
                'type' => 'text',
                'is_required' => true,
                'is_visible' => true,
            ]);

        $response->assertCreated()
            ->assertJsonStructure(['data', 'message'])
            ->assertJsonPath('data.name', 'Test Field')
            ->assertJsonPath('data.type', 'text');

        $this->assertDatabaseHas('survey_fields', [
            'survey_id' => $this->survey->id,
            'name' => 'Test Field',
        ]);
    }

    public function test_create_field_with_config_array(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/surveys/{$this->survey->id}/fields", [
                'name' => 'Config Field',
                'label' => 'Config Label',
                'field_key' => 'config_field_key',
                'type' => 'select',
                'config' => ['options' => ['a', 'b', 'c']],
                'is_required' => false,
                'is_visible' => true,
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('survey_fields', [
            'survey_id' => $this->survey->id,
            'name' => 'Config Field',
        ]);
    }

    public function test_create_field_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/surveys/{$this->survey->id}/fields", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'label', 'type']);
    }

    public function test_create_field_validates_required_field_key(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/surveys/{$this->survey->id}/fields", [
                'name' => 'No Key Field',
                'label' => 'No Key Label',
                'type' => 'text',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['field_key']);
    }

    public function test_create_field_validates_unique_field_key_per_survey(): void
    {
        SurveyField::factory()->create([
            'survey_id' => $this->survey->id,
            'field_key' => 'duplicate_key',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/surveys/{$this->survey->id}/fields", [
                'name' => 'Another Field',
                'label' => 'Another Label',
                'type' => 'text',
                'field_key' => 'duplicate_key',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['field_key']);
    }

    public function test_cannot_create_field_on_published_survey(): void
    {
        $publishedSurvey = Survey::factory()->create([
            'organization_id' => 1,
            'created_by' => $this->user->id,
            'status' => 'published',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/surveys/{$publishedSurvey->id}/fields", [
                'name' => 'New Field',
                'label' => 'New Label',
                'field_key' => 'some_key',
                'type' => 'text',
            ]);

        $response->assertForbidden();
    }

    public function test_create_field_with_multiple_types(): void
    {
        $types = ['text', 'textarea', 'number', 'select', 'radio', 'checkbox', 'date', 'rating'];

        foreach ($types as $index => $type) {
            $response = $this->actingAs($this->user)
                ->postJson("/api/surveys/{$this->survey->id}/fields", [
                    'name' => "Field {$type}",
                    'label' => "Label {$type}",
                    'type' => $type,
                    'field_key' => "key_{$type}_{$index}",
                ]);

            $response->assertCreated();
        }
    }

    public function test_create_field_requires_auth(): void
    {
        $this->postJson("/api/surveys/{$this->survey->id}/fields", [
            'name' => 'Test',
            'label' => 'Test',
            'field_key' => 'test_key',
            'type' => 'text',
        ])->assertUnauthorized();
    }

    // ========== SurveyField - update ==========

    public function test_can_update_survey_field(): void
    {
        $field = SurveyField::factory()->create([
            'survey_id' => $this->survey->id,
            'name' => 'Old Name',
            'label' => 'Old Label',
            'type' => 'text',
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/surveys/{$this->survey->id}/fields/{$field->id}", [
                'name' => 'New Name',
                'label' => 'New Label',
                'field_key' => $field->field_key,
                'type' => 'textarea',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $this->assertDatabaseHas('survey_fields', [
            'id' => $field->id,
            'name' => 'New Name',
        ]);
    }

    public function test_update_field_validates_required_fields(): void
    {
        $field = SurveyField::factory()->create([
            'survey_id' => $this->survey->id,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/surveys/{$this->survey->id}/fields/{$field->id}", [
                'name' => '',
                'label' => '',
                'type' => '',
            ]);

        $response->assertUnprocessable();
    }

    public function test_cannot_update_field_on_published_survey(): void
    {
        $publishedSurvey = Survey::factory()->create([
            'organization_id' => 1,
            'created_by' => $this->user->id,
            'status' => 'published',
        ]);

        $field = SurveyField::factory()->create([
            'survey_id' => $publishedSurvey->id,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/surveys/{$publishedSurvey->id}/fields/{$field->id}", [
                'name' => 'Updated',
                'label' => 'Updated',
                'field_key' => $field->field_key,
                'type' => 'text',
            ]);

        $response->assertForbidden();
    }

    public function test_update_field_requires_auth(): void
    {
        $field = SurveyField::factory()->create(['survey_id' => $this->survey->id]);

        $this->putJson("/api/surveys/{$this->survey->id}/fields/{$field->id}", [
            'name' => 'Test',
            'label' => 'Test',
            'field_key' => $field->field_key,
            'type' => 'text',
        ])->assertUnauthorized();
    }

    // ========== SurveyField - destroy ==========

    public function test_can_delete_survey_field(): void
    {
        $field = SurveyField::factory()->create([
            'survey_id' => $this->survey->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/surveys/{$this->survey->id}/fields/{$field->id}");

        $response->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertDatabaseMissing('survey_fields', ['id' => $field->id]);
    }

    public function test_cannot_delete_field_on_published_survey(): void
    {
        $publishedSurvey = Survey::factory()->create([
            'organization_id' => 1,
            'created_by' => $this->user->id,
            'status' => 'published',
        ]);

        $field = SurveyField::factory()->create([
            'survey_id' => $publishedSurvey->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/surveys/{$publishedSurvey->id}/fields/{$field->id}");

        $response->assertForbidden();
    }

    public function test_delete_field_requires_auth(): void
    {
        $field = SurveyField::factory()->create(['survey_id' => $this->survey->id]);

        $this->deleteJson("/api/surveys/{$this->survey->id}/fields/{$field->id}")
            ->assertUnauthorized();
    }

    // ========== SurveyField - reorder ==========

    public function test_can_reorder_survey_fields(): void
    {
        $field1 = SurveyField::factory()->create(['survey_id' => $this->survey->id, 'order' => 1]);
        $field2 = SurveyField::factory()->create(['survey_id' => $this->survey->id, 'order' => 2]);
        $field3 = SurveyField::factory()->create(['survey_id' => $this->survey->id, 'order' => 3]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/surveys/{$this->survey->id}/fields/reorder", [
                'fields' => [$field3->id, $field1->id, $field2->id],
            ]);

        $response->assertOk()
            ->assertJsonStructure(['message']);
    }

    public function test_reorder_validates_fields_array(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/surveys/{$this->survey->id}/fields/reorder", [
                'fields' => [],
            ]);

        $response->assertUnprocessable();
    }

    public function test_reorder_requires_auth(): void
    {
        $this->postJson("/api/surveys/{$this->survey->id}/fields/reorder", [
            'fields' => [1, 2],
        ])->assertUnauthorized();
    }

    // ========== SurveyInvitation - index ==========

    public function test_can_list_survey_invitations(): void
    {
        $this->createInvitation(['email' => 'inv1@example.com']);
        $this->createInvitation(['email' => 'inv2@example.com']);
        $this->createInvitation(['email' => 'inv3@example.com']);

        $response = $this->actingAs($this->user)
            ->getJson("/api/surveys/{$this->survey->id}/invitations");

        $response->assertOk();
        $this->assertGreaterThanOrEqual(3, count($response->json('data')));
    }

    public function test_list_invitations_requires_auth(): void
    {
        $this->getJson("/api/surveys/{$this->survey->id}/invitations")
            ->assertUnauthorized();
    }

    // ========== SurveyInvitation - store ==========

    public function test_can_create_survey_invitation(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/surveys/{$this->survey->id}/invitations", [
                'email' => 'invite@example.com',
                'name' => 'Invited Person',
            ]);

        $response->assertCreated()
            ->assertJsonStructure(['data', 'message', 'url'])
            ->assertJsonPath('data.email', 'invite@example.com');

        $this->assertDatabaseHas('survey_invitations', [
            'survey_id' => $this->survey->id,
            'email' => 'invite@example.com',
        ]);
    }

    public function test_create_invitation_validates_email(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/surveys/{$this->survey->id}/invitations", [
                'email' => 'not-an-email',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_create_invitation_validates_required_email(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/surveys/{$this->survey->id}/invitations", [
                'name' => 'Only Name',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_create_invitation_with_user_link(): void
    {
        $linkedUser = User::factory()->create([
            'department_id' => $this->department->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/surveys/{$this->survey->id}/invitations", [
                'email' => $linkedUser->email,
                'user_id' => $linkedUser->id,
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('survey_invitations', [
            'user_id' => $linkedUser->id,
        ]);
    }

    public function test_create_invitation_generates_token(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/surveys/{$this->survey->id}/invitations", [
                'email' => 'tokentest@example.com',
            ]);

        $response->assertCreated();
        $invitation = SurveyInvitation::where('email', 'tokentest@example.com')->first();
        $this->assertNotNull($invitation->token);
        $this->assertNotEmpty($invitation->token);
    }

    public function test_create_invitation_requires_auth(): void
    {
        $this->postJson("/api/surveys/{$this->survey->id}/invitations", [
            'email' => 'x@x.com',
        ])->assertUnauthorized();
    }

    // ========== SurveyInvitation - bulk create ==========

    public function test_can_bulk_create_invitations(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/surveys/{$this->survey->id}/invitations/bulk", [
                'invitations' => [
                    ['email' => 'bulk1@example.com', 'name' => 'Person 1'],
                    ['email' => 'bulk2@example.com', 'name' => 'Person 2'],
                    ['email' => 'bulk3@example.com', 'name' => 'Person 3'],
                ],
                'max_uses' => 1,
            ]);

        $response->assertCreated()
            ->assertJsonStructure(['data', 'message'])
            ->assertJsonCount(3, 'data');

        $this->assertDatabaseHas('survey_invitations', ['email' => 'bulk1@example.com']);
        $this->assertDatabaseHas('survey_invitations', ['email' => 'bulk2@example.com']);
    }

    public function test_bulk_create_validates_max_100(): void
    {
        $invitations = [];
        for ($i = 0; $i < 101; $i++) {
            $invitations[] = ['email' => "user{$i}@example.com"];
        }

        $response = $this->actingAs($this->user)
            ->postJson("/api/surveys/{$this->survey->id}/invitations/bulk", [
                'invitations' => $invitations,
            ]);

        $response->assertUnprocessable();
    }

    public function test_bulk_create_validates_email_format(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/surveys/{$this->survey->id}/invitations/bulk", [
                'invitations' => [
                    ['email' => 'not-an-email'],
                ],
            ]);

        $response->assertUnprocessable();
    }

    public function test_bulk_create_requires_at_least_one_invitation(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/surveys/{$this->survey->id}/invitations/bulk", [
                'invitations' => [],
            ]);

        $response->assertUnprocessable();
    }

    public function test_bulk_create_requires_auth(): void
    {
        $this->postJson("/api/surveys/{$this->survey->id}/invitations/bulk", [
            'invitations' => [['email' => 'x@x.com']],
        ])->assertUnauthorized();
    }

    // ========== SurveyInvitation - resend ==========

    public function test_can_resend_invitation(): void
    {
        // يجب أن يكون الاستبيان في حالة published ليكون canUse() = true
        $publishedSurvey = Survey::factory()->create([
            'organization_id' => 1,
            'created_by' => $this->user->id,
            'status' => 'published',
            'accepting_responses' => true,
        ]);

        $invitation = $publishedSurvey->invitations()->create([
            'email' => 'resend@example.com',
            'status' => 'active',
            'max_uses' => 5,
            'used_count' => 0,
            'reminder_count' => 0,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/surveys/{$publishedSurvey->id}/invitations/{$invitation->id}/resend");

        $response->assertOk()
            ->assertJsonStructure(['message']);

        // تحقق من تحديث reminded_at
        $invitation->refresh();
        $this->assertNotNull($invitation->reminded_at);
    }

    public function test_resend_requires_auth(): void
    {
        $invitation = $this->createInvitation();

        $this->postJson("/api/surveys/{$this->survey->id}/invitations/{$invitation->id}/resend")
            ->assertUnauthorized();
    }

    // ========== SurveyInvitation - destroy ==========

    public function test_can_delete_active_invitation(): void
    {
        $invitation = $this->createInvitation([
            'email' => 'delete@example.com',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/surveys/{$this->survey->id}/invitations/{$invitation->id}");

        $response->assertOk()
            ->assertJsonStructure(['message']);
    }

    public function test_cannot_delete_used_invitation(): void
    {
        $invitation = $this->createInvitation([
            'email' => 'used@example.com',
            'status' => 'used',
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/surveys/{$this->survey->id}/invitations/{$invitation->id}");

        $response->assertForbidden();
    }

    public function test_delete_invitation_requires_auth(): void
    {
        $invitation = $this->createInvitation();

        $this->deleteJson("/api/surveys/{$this->survey->id}/invitations/{$invitation->id}")
            ->assertUnauthorized();
    }

    // ========== SurveyInvitation - revoke ==========

    public function test_can_revoke_invitation(): void
    {
        $invitation = $this->createInvitation([
            'email' => 'revoke@example.com',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/surveys/{$this->survey->id}/invitations/{$invitation->id}/revoke");

        $response->assertOk()
            ->assertJsonStructure(['message']);

        $invitation->refresh();
        $this->assertNotNull($invitation->revoked_at);
    }

    public function test_revoke_requires_auth(): void
    {
        $invitation = $this->createInvitation();

        $this->postJson("/api/surveys/{$this->survey->id}/invitations/{$invitation->id}/revoke")
            ->assertUnauthorized();
    }
}
