<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LogsPurgeFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public const ALERT_TYPE = 'logs_purge_failed';

    public function __construct(
        public string $stage,
        public string $errorMessage,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Gateway EBP] Échec de la purge des logs');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.logs-purge-failed',
            with: [
                'stage' => $this->stage,
                'errorMessage' => $this->errorMessage,
            ],
        );
    }
}
