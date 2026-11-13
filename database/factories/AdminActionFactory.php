<?php

namespace Database\Factories;

use App\Models\AdminAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminAction>
 */
class AdminActionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => 'account.created',
            'subject_type' => null,
            'subject_id' => null,
            'label' => $this->faker->company(),
            'properties' => null,
            'ip' => $this->faker->ipv4(),
            'occurred_at' => now(),
        ];
    }
}
