<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Policies\BasePolicy;
use InvalidArgumentException;

/**
 * Seeds the platform permission catalogue and guards it against BR-020 /
 * FR-AUTH-017 violations: the four structurally read-only roles must never
 * hold a write-type permission key, enforced here at assignment time (not
 * only hidden in the UI), per CLAUDE.md Section 4, Rule 2.
 *
 * The catalogue below is a Sprint-0 working baseline covering each engine's
 * primary actions; it is not yet a verified 1:1 mapping of every endpoint in
 * 14_TW_API_Specification.md and should be reconciled against that document
 * once the full API surface is built out engine by engine.
 */
class PermissionCatalogueService
{
    /**
     * Roles restricted to read-only access at the permission-catalogue level.
     *
     * @var array<int, string>
     */
    public const array READ_ONLY_ROLES = BasePolicy::READ_ONLY_ROLES;

    /**
     * Permission keys that grant view/read access only. Safe for any role.
     *
     * @var array<int, string>
     */
    public const array READ_TYPE_KEYS = [
        'alert.view',
        'inquiry.view',
        'directive.view',
        'report.view',
        'kpi.view',
        'referral.view',
        'content.view',
        'dashboard.view',
    ];

    /**
     * Permission keys that grant create/edit/transition/delete-type access.
     * MUST NEVER be attached to a read-only role (BR-020, FR-AUTH-017).
     *
     * @var array<int, string>
     */
    public const array WRITE_TYPE_KEYS = [
        'alert.create',
        'alert.edit',
        'alert.delegate',
        'alert.acknowledge',
        'alert.feedback.post',
        'inquiry.create',
        'inquiry.edit',
        'inquiry.transition-status',
        'inquiry.log-event',
        'inquiry.close',
        'inquiry.link',
        'directive.issue',
        'directive.transition-status',
        'directive.add-note',
        'report.create-draft',
        'report.save-section',
        'report.submit',
        'kpi.set-target',
        'kpi.record-actual',
        'referral.record',
        'content.create',
        'content.approve',
        'content.reject',
        'content.classify',
        'user.manage',
        'permission.manage',
        'config.manage',
        'approval.request',
        'approval.decide',
    ];

    /**
     * Seed every role in the catalogue with its permission keys.
     */
    public function seed(): void
    {
        foreach ($this->catalogue() as $roleName => $permissionKeys) {
            $role = Role::where('name', $roleName)->first();

            if ($role === null) {
                continue;
            }

            foreach ($permissionKeys as $permissionKey) {
                $this->assignPermission($role, $permissionKey);
            }
        }
    }

    /**
     * Assign a single permission key to a role, rejecting any attempt to
     * attach a write-type key to a structurally read-only role (BR-020).
     */
    public function assignPermission(Role $role, string $permissionKey): Permission
    {
        if (in_array($role->name, self::READ_ONLY_ROLES, true) && in_array($permissionKey, self::WRITE_TYPE_KEYS, true)) {
            throw new InvalidArgumentException(
                "Cannot assign write-type permission [{$permissionKey}] to structurally read-only role [{$role->name}] (BR-020, FR-AUTH-017)."
            );
        }

        return Permission::updateOrCreate(
            ['role_id' => $role->id, 'permission_key' => $permissionKey],
            ['read_only' => in_array($permissionKey, self::READ_TYPE_KEYS, true)],
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function catalogue(): array
    {
        $viewOnly = self::READ_TYPE_KEYS;

        return [
            'System Administrator' => [...$viewOnly, 'user.manage', 'permission.manage', 'config.manage', 'approval.decide'],
            'Head of Mission' => $viewOnly,
            'Deputy Head of Mission' => $viewOnly,
            'MFA HQ Officer' => $viewOnly,
            'MFA Principal Secretary' => $viewOnly,
            'Ministry Attache' => [
                ...$viewOnly,
                'alert.create', 'alert.edit', 'alert.feedback.post',
                'inquiry.create', 'inquiry.edit', 'inquiry.transition-status', 'inquiry.log-event', 'inquiry.close', 'inquiry.link',
                'report.create-draft', 'report.save-section', 'report.submit',
                'referral.record',
            ],
            'Ministry HQ Officer' => [
                ...$viewOnly,
                'alert.acknowledge', 'alert.feedback.post',
                'directive.issue', 'directive.add-note',
            ],
            'Ministry HQ Director' => $viewOnly,
            'Ministry PS' => [
                ...$viewOnly,
                'alert.delegate', 'alert.acknowledge', 'alert.feedback.post',
                'kpi.set-target',
                'directive.issue',
            ],
            'Ministry Publishing Authority' => [
                ...$viewOnly,
                'content.create', 'content.approve', 'content.reject', 'content.classify',
            ],
            // FR-AUTH-016: HRM&D Officer is read-only, KPI Framework Engine only.
            'HRM&D Officer' => ['kpi.view'],
            'Designated Deputy' => [
                ...$viewOnly,
                'alert.acknowledge', 'alert.feedback.post',
            ],
            'Acting PS' => [
                ...$viewOnly,
                'alert.delegate', 'alert.acknowledge', 'alert.feedback.post',
                'kpi.set-target',
                'directive.issue',
            ],
            'Honorary Consul' => $viewOnly,
            // ADR-006, BR-025: administration of one department only — no
            // operational read keys at all, unlike every other role above.
            'Ministry Administrator' => ['user.manage', 'config.manage', 'approval.request'],
        ];
    }
}
