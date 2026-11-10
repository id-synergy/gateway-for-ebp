<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Envoie un e-mail de test pour vérifier la configuration SMTP (mailer, hôte, auth, expéditeur).
 * À lancer après avoir renseigné les variables MAIL_* du .env.
 */
class TestMail extends Command
{
    protected $signature = 'gateway:test-mail {to : Adresse e-mail destinataire}';

    protected $description = 'Envoie un e-mail de test pour valider la configuration SMTP';

    public function handle(): int
    {
        $to = $this->argument('to');

        $from = config('mail.from.address');
        $mailer = config('mail.default');
        $host = config("mail.mailers.{$mailer}.host");

        $this->info("Envoi d'un e-mail de test…");
        $this->line("  mailer   : {$mailer}");
        $this->line("  hôte     : ".($host ?: '—'));
        $this->line("  de       : ".($from ?: '(non configuré)'));
        $this->line("  à        : {$to}");

        try {
            Mail::raw(
                "Ceci est un e-mail de test de la gateway EBP.\n\n".
                "Si vous le recevez, la configuration SMTP fonctionne.\n".
                'Envoyé le '.now()->format('d/m/Y à H:i:s').'.',
                function ($message) use ($to) {
                    $message->to($to)->subject('Gateway EBP — test SMTP');
                },
            );
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('Échec de l\'envoi : '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('E-mail de test remis au serveur SMTP sans erreur. Vérifiez la boîte de réception de '.$to.'.');

        return self::SUCCESS;
    }
}
