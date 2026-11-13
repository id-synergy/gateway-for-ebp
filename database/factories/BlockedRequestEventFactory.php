<?php

namespace Database\Factories;

use App\Models\BlockedRequestEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlockedRequestEvent>
 */
class BlockedRequestEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'occurred_at' => now(),
            'ip' => $this->faker->ipv4(),
            'path' => 'gescom/api/v1/Items',
            'method' => 'GET',
        ];
    }
}
