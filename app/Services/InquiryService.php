<?php

namespace App\Services;

use App\Enums\InquiryEventType;
use App\Enums\InquiryStatus;
use App\Enums\InquirySubType;
use App\Models\Inquiry;
use App\Models\InquiryEvent;
use App\Models\InquiryNote;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * CLAUDE.md Section 11 / FR-INQ-002, 003, 006 to 008, 012, 014: business
 * logic for the Inquiry and Case Tracker Engine. Inquiries\InquiryController
 * stays thin and delegates every mutation here.
 *
 * FR-INQ-019 (cross-mission matching / linkInquiry()) is implemented via
 * linkInquiry() below, paired with App\Services\InquiryMatchingService for
 * the suggested-match side (AC1). FR-INQ-021's dispute lifecycle is
 * deferred per ISSUE-001 (CLAUDE.md Section 16); sub_type is accepted and
 * persisted but follows the identical workflow as a standard inquiry.
 */
class InquiryService
{
    /**
     * CLAUDE.md Section 8 workflow table, keyed by target status, valued by
     * the statuses that target may be reached from (FR-INQ-006, BR-015).
     *
     * @var array<string, array<int, string>>
     */
    private const array ALLOWED_TRANSITIONS = [
        'received' => ['draft'],
        'in_progress' => ['received', 'pending_external_response'],
        'pending_external_response' => ['in_progress'],
        'resolved' => ['in_progress'],
        'closed' => ['resolved'],
        'cancelled' => ['draft', 'received', 'in_progress'],
    ];

    /**
     * NFR-DATA-002 session task (DPIA-derived retention, see this file's
     * purgeExpiredPii() docblock): closed inquiries are eligible for PII
     * redaction once closed_at is this many months in the past. NOTE: the
     * URD's own literal NFR-DATA-002 text (07_TW_URD Section on Tiered
     * Access for Aged Inquiry Data) describes a *5-year* Director-only
     * access tier, not a 12-month redaction sweep — this constant and
     * purgeExpiredPii() implement the session task's explicit 12-month
     * redaction instruction as given, a deliberate drift from the
     * requirement's literal text that a future session should reconcile.
     */
    private const int PII_RETENTION_MONTHS = 12;

    private const string REDACTED_PLACEHOLDER = '[redacted]';

    public function __construct(private readonly AuditService $auditService) {}

    /**
     * @param  array<string, mixed>  $data  Already validated by StoreInquiryRequest.
     */
    public function logInquiry(array $data, User $logger): Inquiry
    {
        return DB::transaction(fn () => Inquiry::create([
            'ministry_id' => $logger->ministry_id,
            'mission_id' => $logger->mission_id,
            'logged_by_user_id' => $logger->id,
            'reference_number' => $this->generateReferenceNumber(),
            'category' => $data['category'],
            'sub_type' => $data['sub_type'] ?? InquirySubType::Standard->value,
            'inquirer_name' => $data['inquirer_name'],
            'inquirer_organisation' => $data['inquirer_organisation'] ?? null,
            'inquirer_email' => $data['inquirer_email'] ?? null,
            'inquirer_phone' => $data['inquirer_phone'] ?? null,
            'product_or_sector' => $data['product_or_sector'] ?? null,
            'description' => $data['description'] ?? null,
            'date_received' => $data['date_received'],
            'status' => InquiryStatus::Draft,
            'high_value_flag' => $data['high_value_flag'] ?? false,
            'high_value_justification' => $data['high_value_justification'] ?? null,
        ]));
    }

    /**
     * @throws InvalidArgumentException If $newStatus is not a configured
     *                                  status, or the current status may
     *                                  not transition to it (FR-INQ-006).
     */
    public function transitionStatus(Inquiry $inquiry, string $newStatus, User $actor): void
    {
        $allowedSources = self::ALLOWED_TRANSITIONS[$newStatus] ?? null;

        if ($allowedSources === null) {
            throw new InvalidArgumentException("[{$newStatus}] is not a configured workflow status.");
        }

        if (! in_array($inquiry->status->value, $allowedSources, true)) {
            throw new InvalidArgumentException(
                "Cannot transition an inquiry from [{$inquiry->status->value}] to [{$newStatus}] (FR-INQ-006)."
            );
        }

        DB::transaction(function () use ($inquiry, $newStatus, $actor): void {
            $inquiry->forceFill(['status' => $newStatus])->save();

            InquiryEvent::create([
                'inquiry_id' => $inquiry->id,
                'event_type' => InquiryEventType::StatusChanged,
                'note' => "Status changed to {$newStatus}.",
                'logged_by_user_id' => $actor->id,
            ]);
        });
    }

    public function logEvent(Inquiry $inquiry, string $eventType, User $actor, ?string $note): void
    {
        InquiryEvent::create([
            'inquiry_id' => $inquiry->id,
            'event_type' => $eventType,
            'note' => $note,
            'logged_by_user_id' => $actor->id,
        ]);
    }

    /**
     * @throws InvalidArgumentException If $resolutionSummary is blank
     *                                  (FR-INQ-012, BR-016) or the
     *                                  inquiry is not currently resolved
     *                                  (CLAUDE.md Section 8: closed is
     *                                  only reachable from resolved).
     */
    public function closeInquiry(Inquiry $inquiry, string $resolutionSummary, User $actor): void
    {
        if (trim($resolutionSummary) === '') {
            throw new InvalidArgumentException('A resolution summary is required to close an inquiry (FR-INQ-012, BR-016).');
        }

        DB::transaction(function () use ($inquiry, $resolutionSummary, $actor): void {
            $this->transitionStatus($inquiry, InquiryStatus::Closed->value, $actor);

            $inquiry->forceFill([
                'resolution_summary' => $resolutionSummary,
                'closed_at' => now(),
            ])->save();
        });
    }

    /**
     * FR-INQ-019 AC2: links two inquiries symmetrically — each row's
     * linked_inquiry_id is set to point at the other — so the linkage is
     * visible from either inquiry record without transferring ownership
     * between missions (the schema has no separate join table for this,
     * only the single self-referencing linked_inquiry_id column per row,
     * per CLAUDE.md Section 6). Overwrites any prior link on either side;
     * the schema supports at most one active link per inquiry.
     *
     * @throws InvalidArgumentException If $inquiry and $target are the
     *                                  same row, or belong to different
     *                                  ministries.
     */
    public function linkInquiry(Inquiry $inquiry, Inquiry $target, User $actor): void
    {
        if ($inquiry->id === $target->id) {
            throw new InvalidArgumentException('An inquiry cannot be linked to itself (FR-INQ-019).');
        }

        if ($inquiry->ministry_id !== $target->ministry_id) {
            throw new InvalidArgumentException('Inquiries can only be linked within the same ministry (FR-INQ-019).');
        }

        DB::transaction(function () use ($inquiry, $target): void {
            $inquiry->forceFill(['linked_inquiry_id' => $target->id])->save();
            $target->forceFill(['linked_inquiry_id' => $inquiry->id])->save();
        });
    }

    /**
     * NFR-DATA-002 session task: redacts inquirer_name/email/phone on every
     * closed inquiry whose closed_at is older than
     * self::PII_RETENTION_MONTHS, preserving every other field (category,
     * product_or_sector, resolution_summary, etc.) for aggregate/
     * statistical use. Scans withoutGlobalScopes() since this is a
     * platform-wide sweep, not scoped to any one ministry (same reasoning
     * as AlertService's reference-number generator). Excludes rows already
     * redacted so a daily-scheduled re-run neither re-touches a row nor
     * writes a duplicate audit_logs entry for it.
     *
     * @return int Count of inquiries redacted by this run.
     */
    public function purgeExpiredPii(?Carbon $asOf = null): int
    {
        $cutoff = ($asOf ?? now())->copy()->subMonths(self::PII_RETENTION_MONTHS);

        $inquiries = Inquiry::withoutGlobalScopes()
            ->where('status', InquiryStatus::Closed)
            ->whereNotNull('closed_at')
            ->where('closed_at', '<', $cutoff)
            ->where('inquirer_name', '!=', self::REDACTED_PLACEHOLDER)
            ->get();

        foreach ($inquiries as $inquiry) {
            DB::transaction(function () use ($inquiry): void {
                $before = [
                    'inquirer_name' => $inquiry->inquirer_name,
                    'inquirer_email' => $inquiry->inquirer_email,
                    'inquirer_phone' => $inquiry->inquirer_phone,
                ];

                $inquiry->forceFill([
                    'inquirer_name' => self::REDACTED_PLACEHOLDER,
                    'inquirer_email' => null,
                    'inquirer_phone' => null,
                ])->save();

                $this->auditService->record(
                    actor: null,
                    action: 'inquiry.pii_purged',
                    affectedEntityType: Inquiry::class,
                    affectedEntityId: $inquiry->id,
                    changes: ['before' => $before],
                );
            });
        }

        return $inquiries->count();
    }

    public function addNote(Inquiry $inquiry, string $content, User $actor): InquiryNote
    {
        return InquiryNote::create([
            'inquiry_id' => $inquiry->id,
            'authored_by_user_id' => $actor->id,
            'content' => $content,
        ]);
    }

    private function generateReferenceNumber(): string
    {
        $prefix = 'INQ-'.now()->format('Ym').'-';

        // lockForUpdate() cannot be combined with an aggregate count() query
        // on PostgreSQL, so the matching rows are locked and counted in PHP
        // (same approach as AlertService::generateReferenceNumber()).
        $sequence = Inquiry::withoutGlobalScopes()
            ->where('reference_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('id')
            ->count() + 1;

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}
