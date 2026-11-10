<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Admin de démo pour tester le back-office en local.
 * À NE PAS exécuter en production.
 */
class DemoAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'demo@gateway.test'],
            [
                'name' => 'Demo',
                'password' => Hash::make('password'),
                'is_active' => true,
                'is_default_contact' => true,
            ],
        );
    }
}
