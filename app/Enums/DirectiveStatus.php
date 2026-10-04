<?php

namespace App\Enums;

/**
 * Directive lifecycle (URD Section 10.5 lifecycle diagram, FR-DIR-006).
 * 'draft' exists only for the instant between insert and issue inside
 * DirectiveService::issueDirective().
 */
enum DirectiveStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Acknowledged = 'acknowledged';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Closed = 'closed';

    /**
     * Statuses in which the target attache is still expected to act, and
     * which the stale flag (FR-DIR-010) and due reminders (FR-DIR-004) watch.
     *
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return [self::Issued->value, self::Acknowledged->value, self::InProgress->value];
    }

    /**
     * Statuses after which the target completion date no longer matters:
     * the work was delivered (completed/closed) or withdrawn (cancelled).
     *
     * @return array<int, string>
     */
    public static function finishedValues(): array
    {
        return [self::Completed->value, self::Closed->value, self::Cancelled->value];
    }

    public function isOpen(): bool
    {
        return in_array($this->value, self::openValues(), true);
    }

    public function isFinished(): bool
    {
        return in_array($this->value, self::finishedValues(), true);
    }

    /**
     * Completed and closed both count as delivered work (FR-DIR-012).
     */
    public function isDelivered(): bool
    {
        return $this === self::Completed || $this === self::Closed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Issued => 'Issued',
            self::Acknowledged => 'Acknowledged',
            self::InProgress => 'In Progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Closed => 'Closed',
        };
    }
}
