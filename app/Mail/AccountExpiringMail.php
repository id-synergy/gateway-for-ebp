<?php

namespace App\Mail;

use App\Models\EbpAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountExpiringMail extends Mailable
{
    use Queueable, SerializesModels;

    public const ALERT_TYPE = 'account_expiring';

    public function __construct(
        public EbpAccount $account,
        public int $daysLeft,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[Gateway EBP] Compte {$this->account->label} bientôt déconnecté — account about to disconnect",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.account-expiring',
            with: [
                'accountLabel' => $this->account->label,
                'lastSuccessAt' => optional($this->account->token?->last_refreshed_at)->format('d/m/Y H:i') ?? '—',
                'refreshExpiresAt' => optional($this->account->token?->refresh_token_expires_at)->format('d/m/Y') ?? '—',
                'daysLeft' => $this->daysLeft,
                'backofficeUrl' => url('/admin/comptes'),
            ],
        );
    }
}
