<?php

namespace App\Services;

use App\Models\Inquiry;
use App\Models\ReferralEntry;
use App\Models\ReferralOrganisation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * CLAUDE.md Section 11 / FR-REF-002 to 006, BR-021: business logic for the
 * Referral Register Engine. ReferralController stays thin and delegates
 * every mutation here.
 *
 * BR-021 / FR-REF-003 (CRITICAL): recording a referral is a purely internal
 * record-keeping action and MUST NOT trigger or facilitate any external
 * communication to the referred organisation. This service deliberately
 * never calls NotificationService or dispatches any mail/job — unlike
 * AlertService/InquiryService, which do notify internal recipients.
 */
class ReferralService
{
    /**
     * @param  array<string, mixed>  $data  Already validated by StoreReferralRequest.
     */
    public function recordReferral(Inquiry $inquiry, ReferralOrganisation $organisation, array $data, User $actor): ReferralEntry
    {
        // ReferralEntry is registered on App\Observers\ModelObserver
        // (AppServiceProvider::boot(), Session 9), so this create() call is
        // already written to audit_logs as `referral_entry.created` without
        // a manual AuditService call here — same convention as
        // AlertService::submitAlert() / InquiryService::logInquiry().
        return DB::transaction(fn () => ReferralEntry::create([
            'inquiry_id' => $inquiry->id,
            'referral_organisation_id' => $organisation->id,
            'contact_person' => $data['contact_person'] ?? null,
            'referral_date' => $data['referral_date'],
            'referral_method' => $data['referral_method'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'created_by_user_id' => $actor->id,
        ]));
    }

    /**
     * FR-REF-005: breakdown dimensions (organisation, mission, country,
     * sector, period) for the Ministry HQ Officer/Director referral
     * dashboard. ReferralEntry's global scope (HasMinistryScope ->
     * inquiry.ministry_id) already restricts this to the caller's ministry,
     * so no explicit ministry filter is applied here.
     *
     * @param  array<string, mixed>  $filters  Optional: organisation_id, mission_id, date_from, date_to.
     * @return array<string, mixed>
     */
    public function getReferralSummary(array $filters): array
    {
        $entries = ReferralEntry::query()
            ->with(['referralOrganisation', 'inquiry.mission'])
            ->when($filters['organisation_id'] ?? null, fn ($query, $value) => $query->where('referral_organisation_id', $value))
            ->when($filters['mission_id'] ?? null, fn ($query, $value) => $query->whereHas('inquiry', fn ($q) => $q->where('mission_id', $value)))
            ->when($filters['date_from'] ?? null, fn ($query, $value) => $query->whereDate('referral_date', '>=', $value))
            ->when($filters['date_to'] ?? null, fn ($query, $value) => $query->whereDate('referral_date', '<=', $value))
            ->get();

        return [
            'total' => $entries->count(),
            'by_organisation' => $entries
                ->groupBy(fn (ReferralEntry $entry) => $entry->referralOrganisation?->name ?? 'Unknown')
                ->map->count(),
            'by_mission' => $entries
                ->groupBy(fn (ReferralEntry $entry) => $entry->inquiry?->mission?->name ?? 'Unknown')
                ->map->count(),
            'by_country' => $entries
                ->groupBy(fn (ReferralEntry $entry) => $entry->inquiry?->mission?->host_country ?? 'Unknown')
                ->map->count(),
            'by_sector' => $entries
                ->groupBy(fn (ReferralEntry $entry) => $entry->inquiry?->product_or_sector ?? 'Unspecified')
                ->map->count(),
            'by_period' => $entries
                ->groupBy(fn (ReferralEntry $entry) => $entry->referral_date->format('Y-m'))
                ->map->count(),
        ];
    }
}
