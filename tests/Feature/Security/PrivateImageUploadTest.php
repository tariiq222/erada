<?php

namespace Tests\Feature\Security;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

/**
 * M-17: user images are stored on the private disk under an org-scoped path and
 * served only through an authenticated, same-org endpoint — never a public URL.
 */
class PrivateImageUploadTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    private function userIn(Organization $org): User
    {
        $dept = Department::factory()->create(['organization_id' => $org->id, 'is_active' => true]);

        return User::factory()->create(['organization_id' => $org->id, 'department_id' => $dept->id, 'is_active' => true]);
    }

    public function test_upload_returns_authenticated_url_on_private_disk(): void
    {
        Storage::fake('local');
        $org = Organization::factory()->create();
        $user = $this->userIn($org);
        $this->grantEngineCapability($user, Capability::ATTACHMENTS_UPLOAD);

        $res = $this->actingAs($user, 'sanctum')
            ->postJson('/api/upload/image', ['image' => UploadedFile::fake()->image('avatar.png', 32, 32)])
            ->assertOk();

        $this->assertStringContainsString("/api/upload/image/{$org->id}/", $res->json('url'));
        Storage::disk('local')->assertExists($res->json('path'));
    }

    public function test_serve_requires_auth_and_same_org(): void
    {
        Storage::fake('local');
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = $this->userIn($orgA);
        $userB = $this->userIn($orgB);

        Storage::disk('local')->put("uploads/images/{$orgA->id}/pic.png", 'binarydata');
        $url = "/api/upload/image/{$orgA->id}/pic.png";

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($userB, 'sanctum')->get($url)->assertForbidden();
        $this->actingAs($userA, 'sanctum')->get($url)->assertOk();
    }
}
