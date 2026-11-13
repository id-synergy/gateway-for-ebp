<?php

namespace Database\Factories;

use App\Models\AdminAuthEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminAuthEvent>
 */
class AdminAuthEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'email' => fn (array $attrs) => $attrs['user_id'] ? null : $this->faker->safeEmail(),
            'event' => AdminAuthEvent::EVENT_LOGIN,
            'ip' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'occurred_at' => now(),
        ];
    }

    public function login(): static
    {
        return $this->state(fn () => ['event' => AdminAuthEvent::EVENT_LOGIN]);
    }

    public function logout(): static
    {
        return $this->state(fn () => ['event' => AdminAuthEvent::EVENT_LOGOUT]);
    }

    /**
     * Échec de connexion : pas de user rattaché, seul l'e-mail saisi est connu.
     */
    public function failed(): static
    {
        return $this->state(fn () => [
            'event' => AdminAuthEvent::EVENT_FAILED,
            'user_id' => null,
            'email' => $this->faker->safeEmail(),
        ]);
    }
}
