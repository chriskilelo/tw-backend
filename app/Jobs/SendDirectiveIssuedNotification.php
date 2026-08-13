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
 * CLAUDE.md Section 12: stub, dispatched today by NotificationService for
 * the 'directive_issued' trigger type (FR-NOTIF-001 to 003). Will be wired
 * to the actual issued Directive by the Directive and Tasking Engine in
 * Session 14 (FR-DIR-002) — until then it carries only the generic
 * message/link already written to the in-app notifications table.
 */
class SendDirectiveIssuedNotification implements ShouldQueue
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
            'New Directive Issued to You — TradeWatch',
            $this->message,
            $this->link,
        ));
    }
}
