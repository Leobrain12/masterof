<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_user_id' => fake()->unique()->numberBetween(10_000_000, 999_999_999),
            'telegram_username' => fake()->userName(),
            'role' => UserRole::MASTER,
            'name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'is_active' => true,
        ];
    }

    public function superadmin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => UserRole::SUPERADMIN]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => UserRole::ADMIN]);
    }

    public function master(): static
    {
        return $this->state(fn (array $attributes) => ['role' => UserRole::MASTER]);
    }
}
