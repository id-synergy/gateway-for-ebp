<?php

namespace App\Mail;

use App\Models\EbpAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TokenRefreshFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public const ALERT_TYPE = 'token_refresh_failed';

    public function __construct(
        public EbpAccount $account,
        public string $errorMessage,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[Gateway EBP] Échec de renouvellement — compte {$this->account->label}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.token-refresh-failed',
            with: [
                'accountLabel' => $this->account->label,
                'lastSuccessAt' => optional($this->account->token?->last_refreshed_at)->format('d/m/Y H:i') ?? '—',
                'refreshExpiresAt' => optional($this->account->token?->refresh_token_expires_at)->format('d/m/Y') ?? '—',
                'errorMessage' => $this->errorMessage,
                'backofficeUrl' => url('/admin/comptes'),
            ],
        );
    }
}
