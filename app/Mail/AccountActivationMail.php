<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * FR-AUTH-002: activation reuses the password-reset broker/token so a new
 * user sets their initial password through the existing
 * POST /api/v1/password/reset endpoint rather than a second, parallel
 * token mechanism. Link expiry therefore follows
 * config('auth.passwords.users.expire') rather than the 72-hour window
 * named in FR-AUTH-002 AC2; reconciling that gap requires a dedicated
 * activation-token store and is deferred to a future session.
 */
class AccountActivationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $email, public readonly string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Activate your TradeWatch account');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.account-activation',
            with: [
                'activationUrl' => sprintf(
                    '%s/activate?token=%s&email=%s',
                    config('app.frontend_url', config('app.url')),
                    $this->token,
                    urlencode($this->email),
                ),
            ],
        );
    }
}
