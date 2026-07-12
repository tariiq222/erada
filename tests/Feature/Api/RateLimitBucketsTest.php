<?php

namespace Tests\Feature\Api;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\Comment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * Rate-limit (429) coverage for the four canonical public-write buckets
 * defined in app/Providers/AppServiceProvider.php and SurveysServiceProvider:
 *
 *   - survey-submit : 10/min  (keyed by token > fingerprint > IP)
 *   - sensitive     : 30/min  (keyed by user id > IP)
 *   - delete        : 10/min  (keyed by user id > IP)
 *   - uploads       : 10/min  (keyed by user id > IP)
 *
 * The throttle middleware fires before the controller returns, so it counts
 * every attempt regardless of the underlying status (404, 422, 2xx). That
 * lets us hammer a missing survey / project / attachment to prove the bucket
 * fills up exactly at `limit + 1` and the (limit+1)-th call returns 429.
 *
 * The base TestCase auto-injects `X-Skip-Csrf: 1`, so no CSRF ceremony here.
 */
class RateLimitBucketsTest extends TestCase
{
    use GrantsEngineCapability, RefreshDatabase;

    private const SURVEY_LIMIT = 10;

    private const SENSITIVE_LIMIT = 30;

    private const DELETE_LIMIT = 10;

    private const UPLOADS_LIMIT = 10;

    protected Organization $organization;

    protected Department $department;

    protected Project $project;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->organization = Organization::factory()->create();
        $this->department = Department::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
            'is_active' => true,
        ]);

        // The base "api" throttle (200/min) is far above these buckets, so each
        // bucket fills up first. Grant every relevant capability so the controller
        // path is the limiting factor (not authz 403/422 from missing capability).
        // COMMENTS_VIEW + PROJECTS_VIEW satisfy the delete-side canAccessParent
        // gate (the owner shortcut only fires after the parent-access guard
        // returns true).
        $this->grantEngineCapability(
            $this->user,
            [
                Capability::COMMENTS_CREATE,
                Capability::COMMENTS_VIEW,
                Capability::PROJECTS_VIEW,
                Capability::ATTACHMENTS_UPLOAD,
            ],
            'organization',
            $this->organization->id
        );

        Storage::fake('local');
        Storage::fake('public');

        $this->project = Project::factory()->create([
            'organization_id' => $this->organization->id,
            'department_id' => $this->department->id,
        ]);
        $this->assignCanonicalRole($this->user, 'project_manager', 'project', $this->project->id);
    }

    // ============================================================
    // survey-submit : 10/min, public endpoint, no actingAs
    // ============================================================

    public function test_survey_submit_endpoint_is_rate_limited(): void
    {
        // The survey doesn't exist; each call returns 404 (within the bucket).
        // The (limit + 1)-th call must hit the throttle before the controller
        // and return 429.
        $code = 'NON-EXISTENT-CODE';

        $last = null;
        for ($i = 0; $i < self::SURVEY_LIMIT; $i++) {
            $last = $this->postJson("/api/surveys/public/{$code}/submit", [
                'answers' => [],
                'version_hash' => 'never-matches',
            ]);
        }
        $this->assertNotNull($last);
        $this->assertNotSame(
            429,
            $last->status(),
            'first '.self::SURVEY_LIMIT." calls must not be throttled (got {$last->status()})"
        );

        $over = $this->postJson("/api/surveys/public/{$code}/submit", [
            'answers' => [],
            'version_hash' => 'never-matches',
        ]);
        $over->assertStatus(429);
    }

    // ============================================================
    // sensitive : 30/min, applied to POST /api/comments
    // ============================================================

    public function test_sensitive_bucket_is_rate_limited_on_comment_create(): void
    {
        // Each call creates a valid comment (200/201) and counts as 1 against
        // the `sensitive` bucket. On call (limit + 1) the throttle middleware
        // short-circuits with 429.
        $payload = [
            'commentable_type' => 'project',
            'commentable_id' => $this->project->id,
            'content' => 'rate-limit sensitive comment',
        ];

        $last = null;
        for ($i = 0; $i < self::SENSITIVE_LIMIT; $i++) {
            $last = $this->actingAs($this->user, 'sanctum')->postJson('/api/comments', $payload);
        }
        $this->assertNotNull($last);
        $this->assertSame(201, $last->status(), 'first '.self::SENSITIVE_LIMIT.' comments must succeed');

        $over = $this->actingAs($this->user, 'sanctum')->postJson('/api/comments', $payload);
        $over->assertStatus(429);
    }

    // ============================================================
    // delete : 10/min, applied to DELETE /api/comments/{id}
    // ============================================================

    public function test_delete_bucket_is_rate_limited_on_comment_delete(): void
    {
        // Pre-create `limit + 1` comments owned by the actor so each DELETE
        // targets a real row (otherwise we test 404, not the limiter).
        $comments = Comment::factory()->count(self::DELETE_LIMIT + 1)->create([
            'commentable_type' => Project::class,
            'commentable_id' => $this->project->id,
            'user_id' => $this->user->id,
        ]);

        $last = null;
        for ($i = 0; $i < self::DELETE_LIMIT; $i++) {
            $last = $this->actingAs($this->user, 'sanctum')
                ->deleteJson("/api/comments/{$comments[$i]->id}");
        }
        $this->assertNotNull($last);
        $this->assertSame(200, $last->status(), 'first '.self::DELETE_LIMIT.' deletes must succeed');

        $over = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/comments/{$comments[self::DELETE_LIMIT]->id}");
        $over->assertStatus(429);
    }

    // ============================================================
    // uploads : 10/min, applied to POST /api/upload/image
    // ============================================================

    public function test_uploads_bucket_is_rate_limited_on_image_upload(): void
    {
        // Minimal valid 1x1 PNG payload (magic bytes + IHDR + IDAT + IEND).
        $ihdr = "\x00\x00\x00\x0D".'IHDR'."\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00"."\x90\x77\x53\xDE";
        $idat = "\x00\x00\x00\x0C".'IDAT'."\x08\x99\x63\x00\x00\x00\x02\x00\x01"."\xE2\x21\xBC\x33";
        $iend = "\x00\x00\x00\x00".'IEND'."\xAE\x42\x60\x82";
        $png = "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A".$ihdr.$idat.$iend;

        $makeUpload = fn () => UploadedFile::fake()->createWithContent('pixel.png', $png);

        $last = null;
        for ($i = 0; $i < self::UPLOADS_LIMIT; $i++) {
            $last = $this->actingAs($this->user, 'sanctum')
                ->postJson('/api/upload/image', ['image' => $makeUpload()]);
        }
        $this->assertNotNull($last);
        $this->assertNotSame(
            429,
            $last->status(),
            'first '.self::UPLOADS_LIMIT." uploads must not be throttled (got {$last->status()})"
        );

        $over = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/upload/image', ['image' => $makeUpload()]);
        $over->assertStatus(429);
    }
}
