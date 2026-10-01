<?php

namespace App\Policies;

use App\Models\User;
use App\Services\AdministrationService;

/**
 * FR-KPI-001 to 016, FR-SDT-016 to 018 (CLAUDE.md Section 11,
 * App\Services\KpiService). Governs KpiDefinition, KpiProfile, KpiTarget,
 * and KpiActual — none of their names match "KpiPolicy" 1:1 across all
 * four, so this is registered manually in AppServiceProvider, mirroring
 * ReferralPolicy/ReportPolicy's precedent for one policy spanning several
 * related models.
 *
 * KPI targets and actuals are Restricted data (TW-SRS-001 Section 5:
 * "elevated ministry roles"), so reads are role-gated too: the four BR-020
 * mission governance roles, which bypass ministry scoping, would otherwise
 * read every department's actuals. Ministry isolation itself is enforced
 * by each model's global scope and the services' explicit ministry
 * filters; an attache's reads are pinned to their own mission by the
 * controllers. BasePolicy::before() still denies every non-view ability
 * for the four BR-020 roles.
 *
 * FR-SDT-018: this is also the ONLY policy an HRM&D Officer may ever pass
 * BasePolicy::before() on — isKpiScoped() below overrides the base class's
 * false default to true, exempting this policy alone from the blanket
 * denial BasePolicy::before() applies to every other policy for that role.
 * The HRM&D-specific abilities (viewHrmdDashboard/generateAttacheSummary)
 * therefore live here, not on MinistryPolicy alongside the other SDT
 * dashboards, even though they're reached via Sdt\HrmdDashboardController —
 * putting them on MinistryPolicy would make BasePolicy::before() deny an
 * HRM&D Officer's own dashboard before the concrete ability method ever ran.
 */
class KpiPolicy extends BasePolicy
{
    /**
     * ADR-006: KPI definitions and profiles are department configuration;
     * targets, actuals, comparisons and dashboards stay denied (BR-025).
     */
    protected const array MINISTRY_ADMINISTRATION_ABILITIES = ['manageDefinitions', 'manageProfiles', 'viewProfiles'];

    /**
     * FR-KPI-005: "a Ministry HQ Director or Ministry PS" may set targets.
     * Acting PS is included per MinistryPolicy::viewPsDashboard()'s
     * established precedent — activating Acting PS is a literal role swap
     * that carries the standing Ministry PS's full permission set
     * (FR-SDT-004 AC1), including this one.
     */
    private const array TARGET_SETTER_ROLES = ['Ministry HQ Director', 'Ministry PS', 'Acting PS'];

    /**
     * FR-KPI-013, FR-KPI-015, FR-SDT-011: national comparison matrix and
     * formatted report generation. Same role set as TARGET_SETTER_ROLES —
     * FR-SDT-011's "all three SDT director roles" collapse onto this
     * schema's single generic "Ministry HQ Director" role (FR-SDT-010:
     * the three SDT director titles are meant to share one underlying
     * permission template), not three distinct role rows.
     */
    private const array DIRECTOR_ROLES = self::TARGET_SETTER_ROLES;

    /**
     * FR-KPI-007: manual KPI entry, "accessible to the Ministry Attache or
     * Ministry HQ Officer, per configuration."
     */
    private const array MANUAL_ENTRY_ROLES = ['Ministry Attache', 'Ministry HQ Officer'];

    /**
     * FR-KPI-008/010/011, FR-SDT-016: the KPI dashboard — directors and the
     * PS across every mission, the HRM&D Officer read-only across every
     * mission (audit-logged), an attache for their own mission only.
     */
    private const array DASHBOARD_ROLES = ['Ministry HQ Director', 'Ministry PS', 'Acting PS', 'HRM&D Officer', 'Ministry Attache'];

    /**
     * API-001 Section 9, GET /kpi-actuals: the dashboard roles, plus the
     * Ministry HQ Officer, who records manual actuals (FR-KPI-007) and needs
     * to see what is already recorded.
     */
    private const array ACTUAL_READER_ROLES = [...self::DASHBOARD_ROLES, 'Ministry HQ Officer'];

    public function viewAny(User $user): bool
    {
        return in_array($user->role?->name, self::ACTUAL_READER_ROLES, true);
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function viewDashboard(User $user): bool
    {
        return in_array($user->role?->name, self::DASHBOARD_ROLES, true);
    }

    /**
     * FR-KPI-001, FR-SDT-021: defining the KPI library. System
     * Administrator only, including GET — mirrors
     * ReportPolicy::manageTemplate()'s precedent for an SDT-scoped
     * administration screen.
     */
    public function manageDefinitions(User $user): bool
    {
        return AdministrationService::isSystemAdministrator($user) || AdministrationService::isMinistryAdministrator($user);
    }

    /**
     * FR-KPI-002, FR-SDT-021: defining KPI Profiles and assigning them to
     * missions. System Administrator only, including GET.
     */
    public function manageProfiles(User $user): bool
    {
        return AdministrationService::isSystemAdministrator($user) || AdministrationService::isMinistryAdministrator($user);
    }

    /**
     * API-001 Section 9, GET /kpi-profiles: the department's administrators
     * manage profiles; the target setters read them, since a profile's
     * defaults are what a mission override overrides (FR-KPI-003).
     */
    public function viewProfiles(User $user): bool
    {
        return $this->manageProfiles($user) || $this->setTarget($user);
    }

    public function setTarget(User $user): bool
    {
        return in_array($user->role?->name, self::TARGET_SETTER_ROLES, true);
    }

    /**
     * FR-KPI-004/005: the target plan and every target's version history.
     */
    public function viewTargets(User $user): bool
    {
        return $this->setTarget($user);
    }

    public function recordActual(User $user): bool
    {
        return in_array($user->role?->name, self::MANUAL_ENTRY_ROLES, true);
    }

    public function viewComparison(User $user): bool
    {
        return in_array($user->role?->name, self::DIRECTOR_ROLES, true);
    }

    /**
     * FR-KPI-015, and API-001 Section 9 adds the HRM&D Officer (whose
     * downloads are audit-logged, FR-KPI-011).
     */
    public function generateReport(User $user): bool
    {
        return in_array($user->role?->name, [...self::DIRECTOR_ROLES, 'HRM&D Officer'], true);
    }

    /**
     * FR-SDT-016: HRM&D Officer's own dashboard. No System Administrator
     * fallback, matching MinistryPolicy::viewPsDashboard()/
     * viewHqWorkspace()'s established precedent of no SA bypass for a
     * named-role-only SDT dashboard.
     */
    public function viewHrmdDashboard(User $user): bool
    {
        return $user->role?->name === 'HRM&D Officer';
    }

    /**
     * FR-SDT-017.
     */
    public function generateAttacheSummary(User $user): bool
    {
        return $user->role?->name === 'HRM&D Officer';
    }

    /**
     * FR-SDT-018: the one exemption from BasePolicy::before()'s blanket
     * denial of every ability for an HRM&D Officer on every other policy.
     */
    protected function isKpiScoped(): bool
    {
        return true;
    }
}
