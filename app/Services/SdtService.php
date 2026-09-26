<?php

namespace App\Services;

use App\Enums\AlertStatus;
use App\Enums\DirectiveStatus;
use App\Enums\InquiryStatus;
use App\Models\Alert;
use App\Models\Directive;
use App\Models\Inquiry;
use App\Models\Ministry;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * CLAUDE.md Section 11 / FR-SDT-001, FR-SDT-004 to 006, FR-SDT-012,
 * FR-SDT-013: PS dashboard aggregation, the HQ Officer workspace, and
 * Acting PS activation/deactivation.
 *
 * psDashboard() and hqWorkspace() rely entirely on the automatic
 * ministry-scoping global scope (App\Models\Scopes\MinistryScope, bound by
 * the ministry.scope route middleware) rather than filtering by
 * ministry_id explicitly — the same pattern AlertController/
 * InquiryController already use. Both methods must only ever be reached
 * from a route inside the ministry.scope middleware group.
 */
class SdtService
{
    public function __construct(private readonly AuditService $auditService) {}

    /**
     * FR-SDT-001: consolidated PS dashboard — unacknowledged alerts,
     * pending inquiries, and this week's directive summary.
     *
     * @return array<string, mixed>
     */
    public function psDashboard(): array
    {
        return [
            'unacknowledged_alerts_count' => Alert::query()
                ->where('status', '!=', AlertStatus::Acknowledged->value)
                ->count(),
            'pending_inquiries_count' => Inquiry::query()
                ->whereIn('status', [
                    InquiryStatus::Received->value,
                    InquiryStatus::InProgress->value,
                    InquiryStatus::PendingExternalResponse->value,
                ])
                ->count(),
            'directive_summary_this_week' => $this->directiveSummaryForWeek(),
        ];
    }

    /**
     * FR-SDT-012, FR-SDT-013: the HQ Trade Officer's unified workspace.
     *
     * Inquiry carries no assignment column (CLAUDE.md Section 6), so
     * "assigned inquiries" here is the officer's ministry-scoped active
     * queue (not closed/cancelled) rather than a true per-officer
     * assignment — a schema limitation, not an oversight; a dedicated
     * inquiry-assignment column would be a future session's task.
     *
     * @param  array<string, mixed>  $filters  Optional 'mission_id', 'status'.
     * @return array<string, mixed>
     */
    public function hqWorkspace(User $officer, array $filters): array
    {
        $missionId = $filters['mission_id'] ?? null;
        $status = $filters['status'] ?? null;

        $assignedAlerts = Alert::query()
            ->where('assigned_to_user_id', $officer->id)
            ->when($missionId, fn ($query) => $query->where('mission_id', $missionId))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->get();

        $assignedInquiries = Inquiry::query()
            ->whereNotIn('status', [InquiryStatus::Closed->value, InquiryStatus::Cancelled->value])
            ->when($missionId, fn ($query) => $query->where('mission_id', $missionId))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->get();

        $pendingDirectives = Directive::query()
            ->where('issued_by_user_id', $officer->id)
            ->whereNotIn('status', [
                DirectiveStatus::Completed->value,
                DirectiveStatus::Cancelled->value,
                DirectiveStatus::Closed->value,
            ])
            ->when($missionId, fn ($query) => $query->where('mission_id', $missionId))
            ->orderByDesc('created_at')
            ->get();

        return [
            'assigned_alerts' => $assignedAlerts->values(),
            'assigned_inquiries' => $assignedInquiries->values(),
            'pending_directives' => $pendingDirectives->values(),
        ];
    }

    /**
     * FR-SDT-004 to 006, BR-023: activates $target as Acting PS by swapping
     * their role to the Acting PS role — which already carries the
     * near-identical Ministry PS permission set (PermissionCatalogueService)
     * — preserving their standing role to revert to on deactivation.
     * Eligibility is governed by ministry membership, not job title:
     * CLAUDE.md's users table has no title/position column to distinguish
     * "Secretary for Trade" from any other ministry user, mirroring
     * AlertService::activateDesignatedDeputy()'s precedent.
     */
    public function activateActingPs(User $activator, User $target): void
    {
        if ($target->ministry_id === null) {
            throw new InvalidArgumentException('The Acting PS candidate must belong to a ministry.');
        }

        // ADR-006: every activator except a System Administrator is pinned to
        // its own department (the PS, and now the Ministry Administrator).
        if (! AdministrationService::isSystemAdministrator($activator) && $activator->ministry_id !== $target->ministry_id) {
            throw new InvalidArgumentException('The Acting PS candidate must belong to your own department.');
        }

        if (in_array($target->role?->name, ['Ministry PS', 'Acting PS'], true)) {
            throw new InvalidArgumentException('The Acting PS candidate cannot already hold PS authority.');
        }

        $ministry = Ministry::query()->findOrFail($target->ministry_id);

        if ($ministry->acting_ps_active) {
            throw new InvalidArgumentException('An Acting PS assignment is already active for this ministry (BR-023).');
        }

        $actingPsRole = Role::query()->where('name', 'Acting PS')->firstOrFail();

        DB::transaction(function () use ($target, $ministry, $actingPsRole): void {
            $target->forceFill([
                'acting_ps_original_role_id' => $target->role_id,
                'role_id' => $actingPsRole->id,
            ])->save();

            $ministry->forceFill([
                'acting_ps_user_id' => $target->id,
                'acting_ps_active' => true,
            ])->save();
        });

        $this->auditService->record(
            $activator,
            'acting_ps.activated',
            'ministry',
            $ministry->id,
            ['assumed_user_id' => $target->id],
            null,
            $ministry->id,
        );
    }

    /**
     * FR-SDT-006: reverts the currently active Acting PS to their standing
     * role and clears the ministry's assignment.
     */
    public function deactivateActingPs(User $activator, Ministry $ministry): void
    {
        if (! AdministrationService::isSystemAdministrator($activator) && $activator->ministry_id !== $ministry->id) {
            throw new InvalidArgumentException('You can only manage the Acting PS of your own department.');
        }

        if (! $ministry->acting_ps_active || $ministry->acting_ps_user_id === null) {
            throw new InvalidArgumentException('No Acting PS assignment is currently active for this ministry.');
        }

        $target = User::query()->findOrFail($ministry->acting_ps_user_id);

        DB::transaction(function () use ($target, $ministry): void {
            $target->forceFill([
                'role_id' => $target->acting_ps_original_role_id ?? $target->role_id,
                'acting_ps_original_role_id' => null,
            ])->save();

            $ministry->forceFill([
                'acting_ps_user_id' => null,
                'acting_ps_active' => false,
            ])->save();
        });

        $this->auditService->record(
            $activator,
            'acting_ps.deactivated',
            'ministry',
            $ministry->id,
            ['reverted_user_id' => $target->id],
            null,
            $ministry->id,
        );
    }

    /**
     * CLAUDE.md Section 8 reporting calendar week boundary (calendar week,
     * not fiscal — FR-SDT-001 only asks for "this week").
     *
     * @return array<string, mixed>
     */
    private function directiveSummaryForWeek(): array
    {
        $start = Carbon::now()->startOfWeek();
        $end = Carbon::now()->endOfWeek();

        $directives = Directive::query()->whereBetween('created_at', [$start, $end])->get();

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'total' => $directives->count(),
            'by_status' => $directives->groupBy(fn (Directive $directive) => $directive->status->value)->map->count(),
        ];
    }
}
