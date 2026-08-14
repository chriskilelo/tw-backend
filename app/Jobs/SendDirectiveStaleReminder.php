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
 * CLAUDE.md Section 12 / FR-DIR-010: dispatched by NotificationService for
 * the 'directive_stale' trigger type, itself triggered by
 * DirectiveService::flagStaleDirectives() (scheduled daily, CLAUDE.md
 * Section 8 Stale Directive Threshold: 14 days with no progress update).
 * Mirrors the existing stub mail job shape (SendAlertRoutingNotification,
 * SendDirectiveIssuedNotification, SendReportDeadlineReminder) — plain
 * email/message/link values, not the source Directive model.
 */
class SendDirectiveStaleReminder implements ShouldQueue
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
            'Directive Needs a Progress Update — TradeWatch',
            $this->message,
            $this->link,
        ));
    }
}
