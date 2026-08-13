<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * CLAUDE.md Section 12: shared mailable for the in-app-notification-backed
 * trigger types (alert routing, directive issuance, report deadline
 * reminders). The dispatching job supplies its own subject/body; this
 * class carries no trigger-specific logic of its own.
 */
class NotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $message,
        public readonly ?string $link = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.notification',
            with: [
                'message' => $this->message,
                'link' => $this->link !== null
                    ? config('app.frontend_url', config('app.url')).$this->link
                    : null,
            ],
        );
    }
}
