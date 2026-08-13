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
 * the 'report_deadline_reminder' trigger type (FR-NOTIF-001 to 003). Will
 * be wired to the actual reporting-period deadline check by the Periodic
 * Report Engine in Stage 2 (BR-010) — until then it carries only the
 * generic message/link already written to the in-app notifications table.
 */
class SendReportDeadlineReminder implements ShouldQueue
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
            'Report Submission Deadline Reminder — TradeWatch',
            $this->message,
            $this->link,
        ));
    }
}
