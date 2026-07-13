<?php

namespace Tests\Feature\Meetings;

use App\Modules\Core\Authorization\Capability;
use App\Modules\Core\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Meetings\Services\MeetingsCapabilityProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsEngineCapability;
use Tests\TestCase;

class MeetingsCapabilityProviderTest extends TestCase
{
    use GrantsEngineCapability;
    use RefreshDatabase;

    public function test_department_scoped_recommendation_grants_are_exposed_to_the_spa(): void
    {
        $department = Department::factory()->create();
        $user = User::factory()->create([
            'department_id' => $department->id,
            'organization_id' => $department->organization_id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, [
            Capability::RECOMMENDATIONS_VIEW,
            Capability::RECOMMENDATIONS_EDIT,
            Capability::RECOMMENDATIONS_APPROVE,
            Capability::RECOMMENDATIONS_CREATE,
        ], 'department', $department->id);

        $flags = (new MeetingsCapabilityProvider)->userCapabilities($user);

        $this->assertTrue($flags[Capability::RECOMMENDATIONS_VIEW]);
        $this->assertTrue($flags[Capability::RECOMMENDATIONS_EDIT]);
        $this->assertTrue($flags[Capability::RECOMMENDATIONS_APPROVE]);
        $this->assertFalse($flags[Capability::RECOMMENDATIONS_CREATE]);
    }

    public function test_organization_scoped_create_grant_remains_recordless(): void
    {
        $department = Department::factory()->create();
        $user = User::factory()->create([
            'department_id' => $department->id,
            'organization_id' => $department->organization_id,
            'is_active' => true,
        ]);
        $this->grantEngineCapability($user, Capability::RECOMMENDATIONS_CREATE);

        $flags = (new MeetingsCapabilityProvider)->userCapabilities($user);

        $this->assertTrue($flags[Capability::RECOMMENDATIONS_CREATE]);
    }
}
