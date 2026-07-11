<?php

namespace Tests\Feature\Api\Shared;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogExportSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_export_applies_the_same_description_search_as_index(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $user->assignRole('super_admin');

        $matching = ActivityLog::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'action' => ActivityLog::ACTION_UPDATED,
            'description' => 'Governance policy needle updated',
            'loggable_type' => User::class,
            'loggable_id' => $user->id,
        ]);
        ActivityLog::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'action' => ActivityLog::ACTION_UPDATED,
            'description' => 'Unrelated audit entry',
            'loggable_type' => User::class,
            'loggable_id' => $user->id,
        ]);

        $index = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/activity-logs?search=needle');

        $index->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id);

        $export = $this->actingAs($user, 'sanctum')
            ->get('/api/admin/activity-logs/export?format=json&search=needle');

        $export->assertOk();
        $payload = json_decode($export->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $payload['count']);
        $this->assertSame($matching->id, $payload['logs'][0]['id']);
        $this->assertSame('Governance policy needle updated', $payload['logs'][0]['description']);
    }
}
