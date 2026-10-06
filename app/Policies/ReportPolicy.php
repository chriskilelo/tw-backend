<?php

namespace App\Policies;

use App\Models\PeriodicReport;
use App\Models\User;
use App\Services\AdministrationService;

/**
 * FR-RPT-* role requirements (URD Section 10.2, API-001 Section 5).
 * BasePolicy::before() already denies every non-view ability for the four
 * BR-020 read-only roles, and every ability for the HRM&D Officer and (bar
 * the template abilities) the Ministry Administrator, before any method
 * here runs.
 *
 * Governs both PeriodicReport (report instances) and ReportTemplateSection
 * (template configuration), registered against both in AppServiceProvider.
 *
 * Reading is a role check, not "true": the mission-governance roles bypass
 * ministry scoping, so an open viewAny would hand them every department's
 * reports. PeriodicReport::visibleTo() applies the same rules to lists:
 *
 *  - Ministry Attache: every report of their own mission, drafts included
 *    (BR-001); the only role that writes, and only its mission's drafts.
 *  - HQ review roles: the department's submitted reports, read-only
 *    (FR-RPT-017, FR-SDT-007, FR-SDT-015). A draft is the mission's work in
 *    progress; HQ follows it through the compliance dashboard instead.
 *  - Head / Deputy Head of Mission: their mission's submitted reports,
 *    read-only (FR-HOM-001 AC2, FR-HOM-003).
 *  - MFA roles see metadata only (FR-MFA-001 AC2), through the MFA
 *    awareness view, never the reports themselves.
 */
class ReportPolicy extends BasePolicy
{
    /**
     * @var array<int, string>
     */
    public const array AUTHOR_ROLES = ['Ministry Attache'];

    /**
     * FR-RPT-017 (HQ Officer), FR-SDT-007 (Director of External Trade, the
     * Publishing Authority), FR-SDT-008/009 (Directors), API-001 (PS).
     * Acting PS carries the full PS permission set (FR-SDT-004 AC1).
     *
     * @var array<int, string>
     */
    public const array REVIEWER_ROLES = [
        'Ministry HQ Officer',
        'Ministry HQ Director',
        'Ministry PS',
        'Acting PS',
        'Ministry Publishing Authority',
    ];

    /**
     * FR-RPT-018: "viewed by a Ministry HQ Director or Ministry PS".
     *
     * @var array<int, string>
     */
    public const array COMPLIANCE_ROLES = ['Ministry HQ Director', 'Ministry PS', 'Acting PS'];

    /**
     * ADR-006: template versions are department configuration; report
     * instances stay denied to a Ministry Administrator (BR-025).
     */
    protected const array MINISTRY_ADMINISTRATION_ABILITIES = ['manageTemplate', 'viewTemplates'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, [...self::AUTHOR_ROLES, ...self::REVIEWER_ROLES, ...self::MISSION_OVERSIGHT_ROLES], true);
    }

    public function view(User $user, PeriodicReport $report): bool
    {
        $role = $user->role?->name;

        return match (true) {
            in_array($role, self::AUTHOR_ROLES, true) => $this->isOwnMission($user, $report),
            in_array($role, self::MISSION_OVERSIGHT_ROLES, true) => $this->isOwnMission($user, $report) && $report->isSubmitted(),
            in_array($role, self::REVIEWER_ROLES, true) => $report->isSubmitted(),
            default => false,
        };
    }

    /**
     * FR-RPT-003: a Ministry Attache, for their own mission — an account
     * with no mission posting has nothing to report on.
     */
    public function create(User $user): bool
    {
        return in_array($user->role?->name, self::AUTHOR_ROLES, true) && $user->mission_id !== null;
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
     * draft-owning-attache condition the other mutating abilities require —
     * a report that is no longer a draft is denied here, not left for the
     * service layer to reject.
     */
    public function submit(User $user, PeriodicReport $report): bool
    {
        return $this->isEditableByOwningAttache($user, $report);
    }

    /**
     * Discarding a draft started in error (for the wrong period, say). A
     * submitted report is the official record and can never be deleted.
     */
    public function delete(User $user, PeriodicReport $report): bool
    {
        return $this->isEditableByOwningAttache($user, $report);
    }

    /**
     * FR-RPT-018: the compliance dashboard. No model instance to check — a
     * class-level ability, mirroring manageTemplate()'s precedent.
     */
    public function viewCompliance(User $user): bool
    {
        return in_array($user->role?->name, self::COMPLIANCE_ROLES, true);
    }

    /**
     * FR-RPT-001/BR-006: creating template versions (System Administrator,
     * or a Ministry Administrator for its own department).
     */
    public function manageTemplate(User $user): bool
    {
        return AdministrationService::isSystemAdministrator($user) || AdministrationService::isMinistryAdministrator($user);
    }

    /**
     * API-001 Section 5: GET /report-templates is also open to the PS.
     */
    public function viewTemplates(User $user): bool
    {
        return $this->manageTemplate($user) || in_array($user->role?->name, ['Ministry PS', 'Acting PS'], true);
    }

    private function isOwnMission(User $user, PeriodicReport $report): bool
    {
        return $user->mission_id !== null && $user->mission_id === $report->mission_id;
    }

    private function isEditableByOwningAttache(User $user, PeriodicReport $report): bool
    {
        return in_array($user->role?->name, self::AUTHOR_ROLES, true)
            && $this->isOwnMission($user, $report)
            && $report->isDraft();
    }
}
