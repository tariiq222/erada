<?php

namespace Database\Factories;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingResolution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeetingResolution>
 */
class MeetingResolutionFactory extends Factory
{
    protected $model = MeetingResolution::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'meeting_id' => Meeting::factory(),
            'kind' => MeetingResolution::KIND_RECOMMENDATION,
            'title' => 'مخرج '.$this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'owner_id' => User::factory(),
            'created_by' => User::factory(),
            'status' => MeetingResolution::STATUS_OPEN,
            'priority' => MeetingResolution::PRIORITY_MEDIUM,
            'due_date' => now()->addWeek(),
        ];
    }

    public function decision(): self
    {
        return $this->state(['kind' => MeetingResolution::KIND_DECISION]);
    }

    public function recommendation(): self
    {
        return $this->state(['kind' => MeetingResolution::KIND_RECOMMENDATION]);
    }

    public function inProgress(): self
    {
        return $this->state(['status' => MeetingResolution::STATUS_IN_PROGRESS]);
    }

    public function completed(): self
    {
        return $this->state([
            'status' => MeetingResolution::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function cancelled(): self
    {
        return $this->state([
            'status' => MeetingResolution::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    public function onHold(): self
    {
        return $this->state([
            'hold_reason' => 'بانتظار معلومات إضافية',
            'hold_until' => now()->addDays(7),
            'hold_at' => now(),
            'hold_by' => User::factory(),
        ]);
    }
}
