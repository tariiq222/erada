<?php

namespace Database\Factories;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\Meetings\Models\Recommendation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recommendation>
 */
class RecommendationFactory extends Factory
{
    protected $model = Recommendation::class;

    public function definition(): array
    {
        return [
            'title' => 'توصية '.$this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'priority' => Recommendation::PRIORITY_MEDIUM,
            'status' => Recommendation::STATUS_PROPOSED,
            'kind' => Recommendation::KIND_ACTION_ITEM,
            'assignee_id' => User::factory(),
            'due_date' => now()->addWeek(),
            'organization_id' => Organization::factory(),
        ];
    }

    public function proposed(): static
    {
        return $this->state(['status' => Recommendation::STATUS_PROPOSED]);
    }

    public function accepted(): static
    {
        return $this->state(['status' => Recommendation::STATUS_ACCEPTED]);
    }

    public function overdue(): static
    {
        return $this->state([
            'status' => Recommendation::STATUS_ACCEPTED,
            'due_date' => now()->subDays(3),
        ]);
    }

    /**
     * State: ruling-kind recommendation pending a decision.
     */
    public function ruling(): static
    {
        return $this->state(fn () => [
            'kind' => Recommendation::KIND_RULING,
            'type' => 'approval',
            'status' => Recommendation::STATUS_PENDING,
            'assignee_id' => null,
            'due_date' => null,
        ]);
    }

    /**
     * State: action_item-kind recommendation (default kind, explicit for tests).
     */
    public function actionItem(): static
    {
        return $this->state(['kind' => Recommendation::KIND_ACTION_ITEM]);
    }
}
