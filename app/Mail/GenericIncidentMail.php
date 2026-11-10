<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GenericIncidentMail extends Mailable
{
    use Queueable, SerializesModels;

    public const ALERT_TYPE = 'generic_incident';

    public function __construct(
        public string $title,
        public string $type,
        public string $subjectLabel,
        public string $detail,
        public string $actionExpected,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[Gateway EBP] {$this->title}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.generic-incident',
            with: [
                'title' => $this->title,
                'type' => $this->type,
                'subjectLabel' => $this->subjectLabel,
                'detail' => $this->detail,
                'actionExpected' => $this->actionExpected,
            ],
        );
    }
}
