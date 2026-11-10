<?php

namespace Database\Factories;

use App\Models\EbpAccount;
use App\Models\EbpFolder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EbpFolder>
 */
class EbpFolderFactory extends Factory
{
    protected $model = EbpFolder::class;

    public function definition(): array
    {
        return [
            'ebp_account_id' => EbpAccount::factory(),
            'folder_id' => (string) fake()->numberBetween(1000, 9999),
            'short_name' => 'INV'.fake()->numberBetween(10, 40),
            'label' => fake()->words(2, true),
            'is_active' => true,
        ];
    }
}
