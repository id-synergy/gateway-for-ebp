<?php

namespace Database\Factories;

use App\Models\EbpAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\AccountEvent>
 */
class AccountEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ebp_account_id' => EbpAccount::factory(),
            'type' => 'connected',
            'level' => 'success',
            'message' => 'Compte connecté à EBP.',
            'context' => null,
            'occurred_at' => now(),
        ];
    }
}
