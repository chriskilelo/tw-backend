<?php

namespace App\Policies;

use App\Models\ReferralEntry;
use App\Models\User;
use App\Services\AdministrationService;

/**
 * FR-REF-* role requirements. Registered manually in
 * App\Providers\AppServiceProvider for both ReferralEntry and
 * ReferralOrganisation via Gate::policy() — Laravel's convention-based
 * auto-discovery would otherwise look for ReferralEntryPolicy /
 * ReferralOrganisationPolicy, but both models share the same rules here.
 * BasePolicy::before() already denies every non-view ability for the four
 * BR-020 read-only roles before any method here runs.
 *
 * Mirrors App\Services\PermissionCatalogueService::catalogue():
 * referral.record -> Ministry Attache only. FR-REF-005's summary dashboard
 * is Ministry HQ Officer / Ministry HQ Director only.
 */
class ReferralPolicy extends BasePolicy
{
    /**
     * ADR-006: only the organisation registry is department configuration;
     * referral entries stay denied to a Ministry Administrator (BR-025).
     */
    protected const array MINISTRY_ADMINISTRATION_ABILITIES = ['manage'];

    private const array SUMMARY_ROLES = ['Ministry HQ Officer', 'Ministry HQ Director'];

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ReferralEntry $referralEntry): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role?->name === 'Ministry Attache';
    }

    public function uploadAttachment(User $user, ReferralEntry $referralEntry): bool
    {
        return $user->role?->name === 'Ministry Attache' && $referralEntry->created_by_user_id === $user->id;
    }

    public function viewSummary(User $user): bool
    {
        return in_array($user->role?->name, self::SUMMARY_ROLES, true);
    }

    /**
     * Session 14 / FR-SDT-023: the SDT-scoped referral organisation registry
     * administration screen (App\Http\Controllers\Api\Sdt\ConfigController)
     * is System Administrator only end-to-end, including GET — a separate
     * ability name from viewAny()/create() above so it doesn't collide with
     * the Ministry Attache-facing referral endpoints those already govern.
     */
    public function manage(User $user): bool
    {
        return AdministrationService::isSystemAdministrator($user) || AdministrationService::isMinistryAdministrator($user);
    }
}
