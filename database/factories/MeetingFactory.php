<?php

namespace Database\Factories;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Meeting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Meeting>
 */
class MeetingFactory extends Factory
{
    protected $model = Meeting::class;

    public function definition(): array
    {
        return [
            'title' => 'اجتماع '.$this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 60,
            'location' => 'قاعة '.$this->faker->word(),
            'virtual_link' => 'https://meet.example.com/'.$this->faker->uuid(),
            'agenda' => '1. '.$this->faker->sentence()."\n2. ".$this->faker->sentence(),
            'minutes' => null,
            'status' => Meeting::STATUS_SCHEDULED,
            'organizer_id' => User::factory(),
            'subject_type' => null,
            'subject_id' => null,
            'organization_id' => Organization::factory(),
        ];
    }

    public function inProgress(): static
    {
        return $this->state(['status' => Meeting::STATUS_IN_PROGRESS]);
    }

    public function completed(): static
    {
        return $this->state(['status' => Meeting::STATUS_COMPLETED]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => Meeting::STATUS_CANCELLED]);
    }
}
