<?php

namespace App\Jobs;

use App\Mail\AccountActivationMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * CLAUDE.md Section 12: dispatched when a System Administrator creates a
 * user account, or resends activation (FR-AUTH-002). Queued on
 * 'notifications' per the background jobs reference table.
 */
class SendAccountActivationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $email, public readonly string $token)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        Mail::to($this->email)->send(new AccountActivationMail($this->email, $this->token));
    }
}
