<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * FR-AUTH-011: the reset link is valid for 60 minutes
 * (config('auth.passwords.users.expire')).
 */
class PasswordResetMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $email, public readonly string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your TradeWatch password');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.password-reset',
            with: [
                'resetUrl' => sprintf(
                    '%s/password/reset?token=%s&email=%s',
                    config('app.frontend_url', config('app.url')),
                    $this->token,
                    urlencode($this->email),
                ),
                'expiresInMinutes' => config('auth.passwords.users.expire'),
            ],
        );
    }
}
