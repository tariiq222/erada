<?php

namespace Database\Factories;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Meeting;
use App\Modules\Meetings\Models\MeetingAgendaItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeetingAgendaItem>
 */
class MeetingAgendaItemFactory extends Factory
{
    protected $model = MeetingAgendaItem::class;

    public function definition(): array
    {
        return [
            'meeting_id' => Meeting::factory(),
            'title' => 'نقطة '.$this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'proposed_by_id' => User::factory(),
            'status' => MeetingAgendaItem::STATUS_APPROVED,
            'position' => 0,
            'organization_id' => Organization::factory(),
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => MeetingAgendaItem::STATUS_PENDING]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => MeetingAgendaItem::STATUS_REJECTED]);
    }
}
