<?php

namespace App\Jobs;

use App\Mail\NotificationMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * CLAUDE.md Section 12 / FR-DIR-004: dispatched by NotificationService for
 * the 'directive_due_reminder' trigger type, itself triggered by
 * DirectiveService::sendDueReminders() (scheduled daily; 7 and 3 days
 * before the target completion date). Mirrors SendDirectiveStaleReminder —
 * plain email/message/link values, not the source Directive model.
 */
class SendDirectiveDueReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $email,
        public readonly string $message,
        public readonly ?string $link = null,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        Mail::to($this->email)->send(new NotificationMail(
            'Directive Due Soon — TradeWatch',
            $this->message,
            $this->link,
        ));
    }
}
