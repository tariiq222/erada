<?php

namespace Database\Factories\RiskManagement;

use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\User;
use App\Modules\RiskManagement\Enums\RiskActionStatus;
use App\Modules\RiskManagement\Enums\RiskActionType;
use App\Modules\RiskManagement\Models\Risk;
use App\Modules\RiskManagement\Models\RiskAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RiskAction>
 */
class RiskActionFactory extends Factory
{
    protected $model = RiskAction::class;

    public function definition(): array
    {
        return [
            'risk_id' => Risk::factory(),
            'organization_id' => Organization::factory(),
            'title' => $this->faker->sentence(4),
            'type' => $this->faker->randomElement(RiskActionType::cases())->value,
            'description' => $this->faker->optional()->paragraph(),
            'owner_id' => User::factory(),
            'due_date' => $this->faker->dateTimeBetween('now', '+3 months'),
            'status' => RiskActionStatus::Pending->value,
            'progress_pct' => 0,
            'notes' => $this->faker->optional()->paragraph(),
            'overdue_notified_at' => null,
        ];
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'due_date' => now()->subDays(2)->toDateString(),
            'status' => RiskActionStatus::InProgress->value,
            'overdue_notified_at' => null,
        ]);
    }
}
