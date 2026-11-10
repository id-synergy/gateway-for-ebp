<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

/**
 * Crée (ou met à jour) un compte administrateur du back-office, en console.
 *
 * Utile au déploiement : premier admin sans 2FA (il l'activera à la première connexion).
 * Les identifiants peuvent être passés en options ou saisis interactivement.
 */
class CreateAdmin extends Command
{
    protected $signature = 'gateway:create-admin
        {--name= : Nom de l\'administrateur}
        {--email= : Adresse e-mail (identifiant de connexion)}
        {--password= : Mot de passe (sinon demandé en secret, ou généré avec --generate)}
        {--generate : Génère un mot de passe fort et l\'affiche}
        {--default-contact : Marque cet admin comme contact par défaut des alertes}';

    protected $description = 'Crée un administrateur du back-office (sans 2FA : à activer à la première connexion)';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Nom de l\'administrateur');
        $email = $this->option('email') ?: $this->ask('Adresse e-mail');

        $generated = false;
        $password = $this->option('password');
        if (! $password && $this->option('generate')) {
            $password = \Illuminate\Support\Str::password(16);
            $generated = true;
        }
        if (! $password) {
            $password = $this->secret('Mot de passe (min. 10 caractères)');
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => 'required|string|max:255',
                'email' => 'required|email',
                'password' => ['required', Password::min(10)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $existing = User::where('email', $email)->exists();

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'is_active' => true,
                'is_default_contact' => (bool) $this->option('default-contact'),
            ],
        );

        $this->info(($existing ? 'Administrateur mis à jour' : 'Administrateur créé').' : '.$user->email);

        if ($generated) {
            $this->newLine();
            $this->warn('Mot de passe généré (à noter maintenant, non réaffiché) :');
            $this->line('  '.$password);
        }

        $this->newLine();
        $this->comment('La double authentification n\'est pas activée : activez-la à la première connexion (page Profil).');

        return self::SUCCESS;
    }
}
