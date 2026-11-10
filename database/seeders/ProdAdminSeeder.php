<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Admin de départ pour un déploiement en production.
 *
 * Crée un administrateur placeholder UNIQUEMENT s'il n'existe encore aucun utilisateur,
 * avec un mot de passe fort généré et affiché une seule fois (à changer, et à sécuriser
 * par la 2FA, dès la première connexion). Idempotent : ne fait rien si un admin existe.
 *
 * Préférez la commande `php artisan gateway:create-admin` pour un contrôle total.
 */
class ProdAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (User::query()->exists()) {
            $this->command?->warn('Un utilisateur existe déjà : aucun admin de départ créé.');

            return;
        }

        $email = 'admin@ebp-gw.local';
        $password = Str::password(16);

        User::create([
            'name' => 'Administrateur',
            'email' => $email,
            'password' => Hash::make($password),
            'is_active' => true,
            'is_default_contact' => true,
        ]);

        $this->command?->newLine();
        $this->command?->info('Admin de départ créé (sans 2FA) :');
        $this->command?->line('  e-mail        : '.$email);
        $this->command?->line('  mot de passe  : '.$password);
        $this->command?->newLine();
        $this->command?->warn('Notez ce mot de passe MAINTENANT (non réaffiché). Changez e-mail + mot de passe et activez la 2FA à la première connexion.');
    }
}
