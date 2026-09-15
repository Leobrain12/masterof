<?php

namespace Database\Factories;

use App\Enums\MasterStatus;
use App\Models\Master;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Master>
 */
class MasterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->master(),
            'name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'status' => MasterStatus::ACTIVE,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    public function onVacation(): static
    {
        return $this->state(fn (array $attributes) => ['status' => MasterStatus::VACATION]);
    }
}
