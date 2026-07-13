<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Authorization\Models\AuthorizationResource;
use App\Modules\Core\Authorization\Models\AuthorizationRole;
use App\Modules\Core\Authorization\Models\AuthorizationRolePermission;
use App\Modules\Meetings\Models\Meeting;
use Database\Seeders\Meetings\MeetingsPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_canonical_meeting_permissions_for_admin(): void
    {
        $this->seed(MeetingsPermissionsSeeder::class);

        $admin = AuthorizationRole::query()->where('name', 'admin')->firstOrFail();
        $resource = AuthorizationResource::query()->where('key', Meeting::class)->firstOrFail();

        $this->assertSame(
            ['create', 'delete', 'edit', 'record_decisions', 'view'],
            AuthorizationRolePermission::query()
                ->where('authorization_role_id', $admin->id)
                ->where('authorization_resource_id', $resource->id)
                ->orderBy('action')
                ->pluck('action')
                ->all(),
        );
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(MeetingsPermissionsSeeder::class);
        $this->seed(MeetingsPermissionsSeeder::class);
        $this->seed(MeetingsPermissionsSeeder::class);

        $admin = AuthorizationRole::query()->where('name', 'admin')->firstOrFail();
        $resource = AuthorizationResource::query()->where('key', Meeting::class)->firstOrFail();

        $this->assertSame(1, AuthorizationRole::query()->where('name', 'admin')->count());
        $this->assertSame(1, AuthorizationResource::query()->where('key', Meeting::class)->count());
        $this->assertSame(5, AuthorizationRolePermission::query()
            ->where('authorization_role_id', $admin->id)
            ->where('authorization_resource_id', $resource->id)
            ->count());
    }
}
