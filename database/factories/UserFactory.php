<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            // Established users have accepted the guidance-only disclaimer; the first-run
            // gate is exercised explicitly via unacknowledged().
            'disclaimer_acknowledged_at' => now(),
            'can_interpret' => false,
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** A brand-new user who has not yet accepted the first-run disclaimer. */
    public function unacknowledged(): static
    {
        return $this->state(fn (array $attributes) => [
            'disclaimer_acknowledged_at' => null,
        ]);
    }

    /** A user the admin has granted the advice-style interpretation capability. */
    public function canInterpret(): static
    {
        return $this->state(fn (array $attributes) => [
            'can_interpret' => true,
        ]);
    }
}
