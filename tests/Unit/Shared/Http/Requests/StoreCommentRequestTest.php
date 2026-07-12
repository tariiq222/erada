<?php

namespace Tests\Unit\Shared\Http\Requests;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Http\Requests\StoreCommentRequest;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class StoreCommentRequestTest extends TestCase
{
    use DatabaseTransactions;

    protected User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // rules() scopes mentioned_users by the authenticated user's organization,
        // so every request that evaluates rules() needs a resolved user.
        $this->actor = User::factory()->create();
    }

    private function makeRequest(): StoreCommentRequest
    {
        $request = new StoreCommentRequest;
        $request->setUserResolver(fn () => $this->actor);

        return $request;
    }

    public function test_valid_minimal_payload_passes(): void
    {
        $project = Project::factory()->create();

        $request = $this->makeRequest();
        $validator = Validator::make([
            'commentable_type' => 'project',
            'commentable_id' => $project->id,
            'content' => 'تعليق اختبار',
        ], $request->rules());

        $this->assertFalse($validator->fails(), 'Errors: '.json_encode($validator->errors()->all()));
    }

    public function test_missing_commentable_type_fails(): void
    {
        $request = $this->makeRequest();
        $validator = Validator::make([
            'commentable_id' => 1,
            'content' => 'x',
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('commentable_type', $validator->errors()->toArray());
    }

    public function test_invalid_commentable_type_fails(): void
    {
        $request = $this->makeRequest();
        $validator = Validator::make([
            'commentable_type' => 'widget',
            'commentable_id' => 1,
            'content' => 'x',
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('commentable_type', $validator->errors()->toArray());
    }

    public function test_missing_content_fails(): void
    {
        $request = $this->makeRequest();
        $validator = Validator::make([
            'commentable_type' => 'project',
            'commentable_id' => 1,
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('content', $validator->errors()->toArray());
    }

    public function test_content_longer_than_5000_fails(): void
    {
        $request = $this->makeRequest();
        $validator = Validator::make([
            'commentable_type' => 'project',
            'commentable_id' => 1,
            'content' => str_repeat('a', 5001),
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('content', $validator->errors()->toArray());
    }

    public function test_mentioned_users_with_nonexistent_id_fails(): void
    {
        $request = $this->makeRequest();
        $validator = Validator::make([
            'commentable_type' => 'project',
            'commentable_id' => 1,
            'content' => 'x',
            'mentioned_users' => [99999],
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $errors = $validator->errors()->toArray();
        $this->assertArrayHasKey('mentioned_users.0', $errors);
    }

    public function test_attachments_array_over_10_fails(): void
    {
        $request = $this->makeRequest();
        // Use a real file fixture
        $files = [];
        for ($i = 0; $i < 11; $i++) {
            $files[] = UploadedFile::fake()->create("f{$i}.pdf", 10);
        }

        $validator = Validator::make([
            'commentable_type' => 'project',
            'commentable_id' => 1,
            'content' => 'x',
            'attachments' => $files,
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('attachments', $validator->errors()->toArray());
    }

    public function test_parent_id_nonexistent_fails(): void
    {
        $request = $this->makeRequest();
        $validator = Validator::make([
            'commentable_type' => 'project',
            'commentable_id' => 1,
            'content' => 'x',
            'parent_id' => 99999,
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('parent_id', $validator->errors()->toArray());
    }

    public function test_commentable_id_zero_fails(): void
    {
        $request = $this->makeRequest();
        $validator = Validator::make([
            'commentable_type' => 'project',
            'commentable_id' => 0,
            'content' => 'x',
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('commentable_id', $validator->errors()->toArray());
    }

    public function test_authorize_returns_true_for_admin_user_on_project_comment(): void
    {
        // Engine: authorize() routes through AccessDecision::can() with the
        // resolved Project as target. Admin's scoped_role_def has
        // is_admin_role=true so it grants COMMENTS_CREATE on the target.
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $this->grantCanonicalAdmin($user);

        $project = Project::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'created_by' => $user->id,
        ]);

        $request = new StoreCommentRequest;
        $request->setUserResolver(fn () => $user);
        $request->merge([
            'commentable_type' => 'project',
            'commentable_id' => $project->id,
        ]);

        $this->assertTrue($request->authorize());
    }

    public function test_authorize_returns_false_when_no_user(): void
    {
        $request = new StoreCommentRequest;
        $request->setUserResolver(fn () => null);

        $this->assertFalse($request->authorize());
    }
}
