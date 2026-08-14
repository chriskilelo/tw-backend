<?php

namespace App\Policies;

use App\Enums\PeriodicReportStatus;
use App\Models\PeriodicReport;
use App\Models\User;

/**
 * FR-RPT-* role requirements (API-001, CLAUDE.md Section 11). BasePolicy::before()
 * already denies every non-view ability for the four BR-020 read-only roles
 * before any method here runs.
 *
 * Governs both PeriodicReport (report instances) and ReportTemplateSection
 * (template configuration) — registered against both model classes in
 * AppServiceProvider, mirroring ReferralPolicy's precedent for a policy
 * whose name doesn't match either model 1:1.
 *
 * view/viewAny is left open to any authenticated ministry-scoped user,
 * matching AlertPolicy/InquiryPolicy's precedent — ministry isolation is
 * enforced by the model's global scope, not this policy. Every mutating
 * ability additionally requires the report still be in draft status
 * (BR-009: a submitted report can never be edited by the submitting
 * attache), even though this session exposes no submit endpoint yet.
 */
class ReportPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PeriodicReport $report): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role?->name === 'Ministry Attache';
    }

    public function updateSection(User $user, PeriodicReport $report): bool
    {
        return $this->isEditableByOwningAttache($user, $report);
    }

    public function addDataRow(User $user, PeriodicReport $report): bool
    {
        return $this->isEditableByOwningAttache($user, $report);
    }

    public function removeDataRow(User $user, PeriodicReport $report): bool
    {
        return $this->isEditableByOwningAttache($user, $report);
    }

    public function carryForward(User $user, PeriodicReport $report): bool
    {
        return $this->isEditableByOwningAttache($user, $report);
    }

    /**
     * FR-RPT-014, BR-009: submitting is only ever legal for the same
     * draft-owning-attache condition the other mutating abilities already
     * require — a report that is no longer draft (i.e. already submitted)
     * is denied here too, rather than left for the service layer to reject.
     */
    public function submit(User $user, PeriodicReport $report): bool
    {
        return $this->isEditableByOwningAttache($user, $report);
    }

    /**
     * FR-RPT-018: the compliance dashboard. No model instance to check — a
     * class-level ability, mirroring manageTemplate()'s precedent.
     */
    public function viewCompliance(User $user): bool
    {
        return in_array($user->role?->name, ['Ministry HQ Director', 'Ministry PS'], true);
    }

    /**
     * FR-RPT-002/BR-006: managing template versions (System Administrator
     * only). No model instance to check — a class-level ability, mirroring
     * MasterDataEntryPolicy::manage()'s precedent.
     */
    public function manageTemplate(User $user): bool
    {
        return $user->role?->name === 'System Administrator';
    }

    private function isEditableByOwningAttache(User $user, PeriodicReport $report): bool
    {
        return $user->role?->name === 'Ministry Attache'
            && $user->mission_id === $report->mission_id
            && $report->status === PeriodicReportStatus::Draft;
    }
}
