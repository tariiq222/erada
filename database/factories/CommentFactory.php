<?php

namespace Database\Factories;

use App\Modules\Core\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Models\Comment;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommentFactory extends Factory
{
    protected $model = Comment::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'commentable_type' => Project::class,
            'commentable_id' => Project::factory(),
            'content' => $this->faker->paragraph(),
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes) => [
            'commentable_type' => Project::class,
            'commentable_id' => $project->id,
        ]);
    }
}
