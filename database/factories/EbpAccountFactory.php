<?php

namespace Database\Factories;

use App\Models\EbpAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EbpAccount>
 */
class EbpAccountFactory extends Factory
{
    protected $model = EbpAccount::class;

    public function definition(): array
    {
        return [
            'label' => fake()->company(),
            'client_id' => 'client_'.fake()->lexify('??????'),
            'client_secret' => 'secret_'.fake()->lexify('????????'),
            'subscription_key_primary' => fake()->sha256(),
            'subscription_key_secondary' => null,
            'active_subscription_key' => 'primary',
            'environment' => 'production',
            'status' => 'disconnected',
            'is_active' => true,
        ];
    }
}
