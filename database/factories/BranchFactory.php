<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->city().' Branch',
            'code' => Str::upper(fake()->unique()->bothify('BR-####')),
            'is_default' => false,
        ];
    }

    /**
     * Indicate that the branch is the tenant's default branch.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Main Branch',
            'code' => Branch::DefaultCode,
            'is_default' => true,
        ]);
    }
}
