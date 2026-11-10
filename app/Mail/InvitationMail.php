<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invitation à rejoindre le back-office : contient le lien d'acceptation (token en clair,
 * jamais stocké) valable 24 h.
 */
class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invitation $invitation,
        public string $acceptUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invitation à la Gateway EBP',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.invitation',
            with: [
                'name' => $this->invitation->name,
                'acceptUrl' => $this->acceptUrl,
                'expiresAt' => $this->invitation->expires_at->format('d/m/Y à H:i'),
            ],
        );
    }
}
