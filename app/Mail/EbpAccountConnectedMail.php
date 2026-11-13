<?php

namespace App\Mail;

use App\Models\EbpAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Confirmation : un compte EBP vient d'être connecté avec succès via OAuth.
 *
 * Envoyée après l'échange du code réussi (première connexion ou reconnexion),
 * pour signaler que le compte est opérationnel.
 */
class EbpAccountConnectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public const ALERT_TYPE = 'account_connected';

    public function __construct(
        public EbpAccount $account,
        public bool $reconnection = false,
        public int $foldersCount = 0,
    ) {}

    public function envelope(): Envelope
    {
        $verb = $this->reconnection ? 'reconnecté / reconnected' : 'connecté / connected';

        return new Envelope(
            subject: "[Gateway EBP] Compte {$verb} — {$this->account->label}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.ebp-account-connected',
            with: [
                'accountLabel' => $this->account->label,
                'reconnection' => $this->reconnection,
                'foldersCount' => $this->foldersCount,
                'connectedAt' => now()->format('d/m/Y H:i'),
                'backofficeUrl' => url('/admin/comptes'),
            ],
        );
    }
}
