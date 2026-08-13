<?php

namespace App\Services;

use App\Enums\InquiryEventType;
use App\Enums\InquiryStatus;
use App\Enums\InquirySubType;
use App\Models\Inquiry;
use App\Models\InquiryEvent;
use App\Models\InquiryNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * CLAUDE.md Section 11 / FR-INQ-002, 003, 006 to 008, 012, 014: business
 * logic for the Inquiry and Case Tracker Engine. Inquiries\InquiryController
 * stays thin and delegates every mutation here.
 *
 * FR-INQ-019 (cross-mission matching / linkInquiry()) is Stage 2 and
 * deliberately not implemented here. FR-INQ-021's dispute lifecycle is
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
