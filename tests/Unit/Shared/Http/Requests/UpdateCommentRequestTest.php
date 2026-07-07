<?php

namespace Tests\Unit\Shared\Http\Requests;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Http\Requests\UpdateCommentRequest;
use App\Modules\Shared\Models\Comment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class UpdateCommentRequestTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeCommentOwnedBy(User $user): Comment
    {
        $project = Project::factory()->create([
            'department_id' => $user->department_id,
        ]);

        return Comment::factory()->create([
            'commentable_type' => Project::class,
            'commentable_id' => $project->id,
            'user_id' => $user->id,
        ]);
    }

    private function makeRequestWithRouteComment(Comment $comment, ?User $user): UpdateCommentRequest
    {
        $request = new UpdateCommentRequest;
        $route = new Route('PUT', '/api/comments/{comment}', []);
        $route->bind(Request::create('/api/comments/'.$comment->id, 'PUT'));
        $route->setParameter('comment', $comment);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    public function test_valid_content_passes(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);

        $request = new UpdateCommentRequest;
        $validator = Validator::make([
            'content' => 'محتوى جديد',
        ], $request->rules());

        $this->assertFalse($validator->fails(), 'Errors: '.json_encode($validator->errors()->all()));
    }

    public function test_empty_content_fails(): void
    {
        $request = new UpdateCommentRequest;
        $validator = Validator::make([
            'content' => '',
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('content', $validator->errors()->toArray());
    }

    public function test_content_over_5000_fails(): void
    {
        $request = new UpdateCommentRequest;
        $validator = Validator::make([
            'content' => str_repeat('x', 5001),
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('content', $validator->errors()->toArray());
    }

    public function test_authorize_returns_true_when_user_owns_comment(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        // super_admin bypasses engine check on parent (UpdateCommentRequest::canAccessParent)
        // — see AccessDecision::whyCan() step 1. This is a unit test of the FormRequest
        // owner-floor path; engine grants are exercised separately.
        $user->assignRole('super_admin');

        $comment = $this->makeCommentOwnedBy($user);
        $request = $this->makeRequestWithRouteComment($comment, $user);

        $this->assertTrue($request->authorize());
    }

    public function test_authorize_returns_false_when_user_does_not_own_comment(): void
    {
        $org = Organization::factory()->create();
        $dept = Department::factory()->create(['organization_id' => $org->id]);
        $owner = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $other = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
            'is_active' => true,
        ]);

        $comment = $this->makeCommentOwnedBy($owner);
        $request = $this->makeRequestWithRouteComment($comment, $other);

        $this->assertFalse($request->authorize());
    }
}
