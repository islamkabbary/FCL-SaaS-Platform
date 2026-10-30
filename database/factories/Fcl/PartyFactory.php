<?php

namespace Database\Factories\Fcl;

use App\Models\Fcl\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Parties for tests. The application creates parties only through CreateParty.
 *
 * @extends Factory<Party>
 */
class PartyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_name' => fake()->unique()->company(),
            'tax_number' => fake()->numerify('###-###-###'),
            'country_code' => 'EG',
            'notes' => null,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the party is no longer in use.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
