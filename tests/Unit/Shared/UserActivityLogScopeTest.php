<?php

namespace Tests\Unit\Shared;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Scopes\UserActivityLogScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserActivityLogScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_super_admin_sees_all_including_null_org_rows(): void
    {
        $org = Organization::factory()->create();
        $dept = \App\Modules\HR\Models\Department::factory()->create(['organization_id' => $org->id]);
        $admin = User::factory()->create([
            'organization_id' => $org->id,
            'department_id' => $dept->id,
        ]);
        $admin->assignRole('super_admin');

        ActivityLog::query()->create([
            'action' => ActivityLog::ACTION_LOGIN,
            'description' => 'super-admin-test-in-org',
            'loggable_type' => User::class,
            'loggable_id' => $admin->id,
            'organization_id' => $org->id,
        ]);
        ActivityLog::query()->create([
            'action' => ActivityLog::ACTION_LOGIN_FAILED,
            'description' => 'super-admin-test-null',
            'loggable_type' => User::class,
            'loggable_id' => null,
            'organization_id' => null,
        ]);

        $query = ActivityLog::query()->where('description', 'like', 'super-admin-test-%');
        app(UserActivityLogScope::class)->apply($query, $admin);
        $this->assertEquals(2, $query->count());
    }

    public function test_normal_user_filters_to_own_org_and_excludes_null(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $deptA = \App\Modules\HR\Models\Department::factory()->create(['organization_id' => $orgA->id]);
        $userA = User::factory()->create([
            'organization_id' => $orgA->id,
            'department_id' => $deptA->id,
        ]);

        ActivityLog::query()->create([
            'action' => ActivityLog::ACTION_LOGIN,
            'description' => 'normal-org-A-test',
            'loggable_type' => User::class,
            'loggable_id' => $userA->id,
            'organization_id' => $orgA->id,
        ]);
        ActivityLog::query()->create([
            'action' => ActivityLog::ACTION_LOGIN,
            'description' => 'normal-org-B-test',
            'loggable_type' => User::class,
            'loggable_id' => $userA->id,
            'organization_id' => $orgB->id,
        ]);
        ActivityLog::query()->create([
            'action' => ActivityLog::ACTION_LOGIN_FAILED,
            'description' => 'normal-null-test',
            'loggable_type' => User::class,
            'loggable_id' => null,
            'organization_id' => null,
        ]);

        $query = ActivityLog::query()->where('description', 'like', 'normal-%-test');
        app(UserActivityLogScope::class)->apply($query, $userA);

        $this->assertEquals(1, $query->count());
        $this->assertEquals($orgA->id, $query->first()->organization_id);
    }

    public function test_normal_user_without_org_returns_empty(): void
    {
        $dept = \App\Modules\HR\Models\Department::factory()->create();
        $user = User::factory()->create([
            'organization_id' => null,
            'department_id' => $dept->id,
        ]);

        ActivityLog::query()->create([
            'action' => ActivityLog::ACTION_LOGIN,
            'description' => 'no-org-test',
            'loggable_type' => User::class,
            'loggable_id' => $user->id,
            'organization_id' => 1,
        ]);

        $query = ActivityLog::query()->where('description', 'no-org-test');
        app(UserActivityLogScope::class)->apply($query, $user);

        $this->assertEquals(0, $query->count());
    }
}