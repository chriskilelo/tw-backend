<?php

namespace App\Services;

use App\Jobs\SendAlertRoutingNotification;
use App\Jobs\SendDirectiveIssuedNotification;
use App\Jobs\SendDirectiveStaleReminder;
use App\Jobs\SendReportDeadlineReminder;
use App\Models\Notification;
use App\Models\User;

/**
 * CLAUDE.md Section 11 / FR-NOTIF-001 to 003, 006: writes the in-app
 * notification record every trigger produces, then dispatches the mapped
 * queued mail job (CLAUDE.md Section 12) unless the recipient has opted
 * out of email for this trigger type via
 * User::$email_notification_preferences.
 */
class NotificationService
{
    /**
     * Maps a trigger_type to the queued mail job that should be dispatched
     * for it. Trigger types with no entry here still write the in-app
     * notification but never dispatch mail.
     *
     * @var array<string, class-string>
     */
    private const array TRIGGER_MAIL_JOBS = [
        'alert_routed' => SendAlertRoutingNotification::class,
        'directive_issued' => SendDirectiveIssuedNotification::class,
        'directive_stale' => SendDirectiveStaleReminder::class,
        'report_deadline_reminder' => SendReportDeadlineReminder::class,
    ];

    public function notify(User $recipient, string $triggerType, string $message, ?string $link = null): void
    {
        Notification::create([
            'recipient_user_id' => $recipient->id,
            'trigger_type' => $triggerType,
            'message' => $message,
            'link' => $link,
        ]);

        if (! $this->hasOptedOut($recipient, $triggerType)) {
            $this->dispatchMailJob($recipient, $triggerType, $message, $link);
        }
    }

    private function hasOptedOut(User $recipient, string $triggerType): bool
    {
        return ($recipient->email_notification_preferences[$triggerType] ?? true) === false;
    }

    private function dispatchMailJob(User $recipient, string $triggerType, string $message, ?string $link): void
    {
        $jobClass = self::TRIGGER_MAIL_JOBS[$triggerType] ?? null;

        if ($jobClass === null) {
            return;
        }

        $jobClass::dispatch($recipient->email, $message, $link);
    }
}
