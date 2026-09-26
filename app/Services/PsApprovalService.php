<?php

namespace App\Services;

use App\Enums\ApprovalRequestStatus;
use App\Enums\ApprovalRequestType;
use App\Enums\UserStatus;
use App\Models\ApprovalRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * FR-AUTH-022/023, BR-026, BR-027: every change to who holds a department's
 * Principal Secretary role that a Ministry Administrator initiates is a
 * request a System Administrator must approve.
 *
 * - A requested new PS account is held in the request payload and created
 *   only on approval, so no half-created account can be self-activated
 *   through the password-reset flow.
 * - Succession swaps the outgoing and incoming PS in one transaction, so
 *   alert routing (AlertService::resolveRoutingRecipient(), which looks up
 *   the department's PS by role name) is never left without a recipient.
 * - Every rule is re-checked at approval time under a department row lock:
 *   the department may have changed since the request was raised.
 */
class PsApprovalService
{
    /**
     * Roles that can never be promoted into, or succeed as, the PS through
     * this workflow: administrators, and Acting PS (whose standing role is
     * held separately and restored on deactivation, FR-SDT-006).
     *
     * @var array<int, string>
     */
    private const array INELIGIBLE_ROLES = [
        AdministrationService::SYSTEM_ADMINISTRATOR,
        AdministrationService::MINISTRY_ADMINISTRATOR,
        AdministrationService::MINISTRY_PS,
        'Acting PS',
    ];

    public function __construct(
        private readonly AdministrationService $administrationService,
        private readonly AuditService $auditService,
        private readonly NotificationService $notificationService,
    ) {}

    public function requestAppointment(User $requester, string $fullName, string $email): ApprovalRequest
    {
        $ministryId = $this->requesterMinistryId($requester);
        $this->assertEmailAvailable($email);
        $this->administrationService->assertPsVacancy($ministryId);

        return $this->submit($requester, ApprovalRequestType::PsAppointment, null, [
            'full_name' => $fullName,
            'email' => Str::lower($email),
        ]);
    }

    public function requestPromotion(User $requester, User $subject): ApprovalRequest
    {
        $ministryId = $this->requesterMinistryId($requester);
        $this->assertEligibleIncoming($subject, $ministryId);
        $this->administrationService->assertPsVacancy($ministryId);

        return $this->submit($requester, ApprovalRequestType::PsPromotion, $subject, null);
    }

    public function requestDeactivation(User $requester, User $subject): ApprovalRequest
    {
        $this->assertSittingPs($subject, $this->requesterMinistryId($requester));

        return $this->submit($requester, ApprovalRequestType::PsDeactivation, $subject, null);
    }

    /**
     * @param  array{full_name: string, email: string}|null  $incomingPerson
     */
    public function requestSuccession(User $requester, User $outgoing, ?User $incomingUser, ?array $incomingPerson): ApprovalRequest
    {
        $ministryId = $this->requesterMinistryId($requester);
        $this->assertSittingPs($outgoing, $ministryId);

        if ($incomingUser !== null) {
            $this->assertEligibleIncoming($incomingUser, $ministryId);
            $payload = ['incoming_user_id' => $incomingUser->id];
        } elseif ($incomingPerson !== null) {
            $this->assertEmailAvailable($incomingPerson['email']);
            $payload = ['incoming_full_name' => $incomingPerson['full_name'], 'incoming_email' => Str::lower($incomingPerson['email'])];
        } else {
            throw new InvalidArgumentException('A succession request must name the incoming Principal Secretary.');
        }

        return $this->submit($requester, ApprovalRequestType::PsSuccession, $outgoing, $payload);
    }

    public function approve(ApprovalRequest $request, User $approver, ?string $ipAddress = null): ApprovalRequest
    {
        $newAccount = null;

        DB::transaction(function () use ($request, $approver, $ipAddress, &$newAccount): void {
            $this->administrationService->lockMinistry($request->ministry_id);
            $request = $this->lockPending($request);

            $newAccount = match ($request->type) {
                ApprovalRequestType::PsAppointment => $this->createPs($request->ministry_id, $request->payload['full_name'], $request->payload['email'], $approver, $ipAddress),
                ApprovalRequestType::PsPromotion => $this->promoteToPs($this->subjectOf($request), $request->ministry_id, $approver, $ipAddress),
                ApprovalRequestType::PsDeactivation => $this->removePs($this->subjectOf($request), $request->ministry_id, $approver, $ipAddress),
                ApprovalRequestType::PsSuccession => $this->succeed($request, $approver, $ipAddress),
            };

            $this->decide($request, $approver, ApprovalRequestStatus::Approved, null);
        });

        if ($newAccount instanceof User) {
            $this->administrationService->sendActivationEmail($newAccount, $approver);
        }

        return $request->fresh();
    }

    public function reject(ApprovalRequest $request, User $approver, string $reason): ApprovalRequest
    {
        DB::transaction(function () use ($request, $approver, $reason): void {
            $this->decide($this->lockPending($request), $approver, ApprovalRequestStatus::Rejected, $reason);
        });

        return $request->fresh();
    }

    public function cancel(ApprovalRequest $request, User $requester): ApprovalRequest
    {
        DB::transaction(function () use ($request, $requester): void {
            $request = $this->lockPending($request);

            $request->forceFill(['status' => ApprovalRequestStatus::Cancelled])->save();

            $this->auditService->record($requester, 'approval_request.cancelled', ApprovalRequest::class, $request->id, null, null, $request->ministry_id);
        });

        return $request->fresh();
    }

    private function submit(User $requester, ApprovalRequestType $type, ?User $subject, ?array $payload): ApprovalRequest
    {
        try {
            $request = DB::transaction(fn (): ApprovalRequest => ApprovalRequest::create([
                'ministry_id' => $requester->ministry_id,
                'type' => $type,
                'status' => ApprovalRequestStatus::Pending,
                'requested_by_user_id' => $requester->id,
                'subject_user_id' => $subject?->id,
                'payload' => $payload,
            ]));
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw new InvalidArgumentException('A Principal Secretary request is already pending for this department. Wait for its decision or cancel it first.');
            }

            throw $e;
        }

        $this->auditService->record($requester, 'approval_request.submitted', ApprovalRequest::class, $request->id, ['type' => $type->value], null, $request->ministry_id);

        $systemAdministrators = User::query()
            ->whereHas('role', fn ($query) => $query->where('name', AdministrationService::SYSTEM_ADMINISTRATOR))
            ->where('status', UserStatus::Active)
            ->get();

        foreach ($systemAdministrators as $systemAdministrator) {
            $this->notificationService->notify(
                $systemAdministrator,
                'approval_requested',
                "{$requester->full_name} has requested a Principal Secretary change ({$this->describe($type)}) and it needs your approval.",
                "/admin/approvals/{$request->id}",
            );
        }

        return $request;
    }

    private function decide(ApprovalRequest $request, User $approver, ApprovalRequestStatus $status, ?string $reason): void
    {
        $request->forceFill([
            'status' => $status,
            'decided_by_user_id' => $approver->id,
            'decided_at' => now(),
            'decision_reason' => $reason,
        ])->save();

        $this->auditService->record($approver, "approval_request.{$status->value}", ApprovalRequest::class, $request->id, $reason ? ['reason' => $reason] : null, null, $request->ministry_id);

        $requester = User::withTrashed()->find($request->requested_by_user_id);

        if ($requester !== null && ! $requester->trashed()) {
            $this->notificationService->notify(
                $requester,
                'approval_decided',
                "Your Principal Secretary request ({$this->describe($request->type)}) was {$status->value}.",
                "/admin/approvals/{$request->id}",
            );
        }
    }

    private function succeed(ApprovalRequest $request, User $approver, ?string $ipAddress): ?User
    {
        $this->removePs($this->subjectOf($request), $request->ministry_id, $approver, $ipAddress);

        if (isset($request->payload['incoming_user_id'])) {
            $incoming = User::query()->find($request->payload['incoming_user_id'])
                ?? throw new InvalidArgumentException('The incoming Principal Secretary account no longer exists.');

            return $this->promoteToPs($incoming, $request->ministry_id, $approver, $ipAddress);
        }

        return $this->createPs($request->ministry_id, $request->payload['incoming_full_name'], $request->payload['incoming_email'], $approver, $ipAddress);
    }

    private function createPs(string $ministryId, string $fullName, string $email, User $approver, ?string $ipAddress): User
    {
        // The request being approved holds this email itself, so only
        // existing accounts are checked here.
        $this->assertEmailAvailable($email, includePendingRequests: false);
        $this->administrationService->assertPsVacancy($ministryId);

        $user = User::create([
            'full_name' => $fullName,
            'email' => $email,
            'password' => Str::random(40),
            'role_id' => $this->psRole()->id,
            'ministry_id' => $ministryId,
            'status' => UserStatus::ActivationPending,
        ]);

        $this->auditService->record($approver, 'user.created', User::class, $user->id, ['full_name' => $fullName, 'email' => $email, 'role' => AdministrationService::MINISTRY_PS], $ipAddress, $ministryId);

        return $user;
    }

    /**
     * Returns null: a promoted account already exists and is not re-sent an
     * activation email.
     */
    private function promoteToPs(User $subject, string $ministryId, User $approver, ?string $ipAddress): ?User
    {
        $this->assertEligibleIncoming($subject, $ministryId);
        $this->administrationService->assertPsVacancy($ministryId, $subject);

        $previousRoleId = $subject->role_id;
        $subject->forceFill(['role_id' => $this->psRole()->id])->save();

        $this->auditService->record($approver, 'user.promoted_to_ps', User::class, $subject->id, ['role_id' => ['from' => $previousRoleId, 'to' => $subject->role_id]], $ipAddress, $ministryId);

        return null;
    }

    private function removePs(User $subject, string $ministryId, User $approver, ?string $ipAddress): null
    {
        $this->assertSittingPs($subject, $ministryId);
        $this->administrationService->deactivateUser($subject, $approver, $ipAddress);

        return null;
    }

    private function requesterMinistryId(User $requester): string
    {
        if (! AdministrationService::isMinistryAdministrator($requester) || $requester->ministry_id === null) {
            throw new InvalidArgumentException('Only a Ministry Administrator can raise a Principal Secretary request.');
        }

        return $requester->ministry_id;
    }

    private function assertSittingPs(User $subject, string $ministryId): void
    {
        if ($subject->ministry_id !== $ministryId || $subject->role?->name !== AdministrationService::MINISTRY_PS || $subject->status === UserStatus::Deactivated) {
            throw new InvalidArgumentException('The named account is not the sitting Principal Secretary of this department.');
        }
    }

    private function assertEligibleIncoming(User $subject, string $ministryId): void
    {
        if ($subject->ministry_id !== $ministryId || $subject->status === UserStatus::Deactivated) {
            throw new InvalidArgumentException('The incoming Principal Secretary must be an active account in this department.');
        }

        if (in_array($subject->role?->name, self::INELIGIBLE_ROLES, true)) {
            throw new InvalidArgumentException('This account cannot be made Principal Secretary from its current role.');
        }
    }

    /**
     * Email must be free across existing accounts (including deactivated,
     * soft-deleted ones — the unique index covers them) and, at submission,
     * across other pending requests that would create an account.
     */
    private function assertEmailAvailable(string $email, bool $includePendingRequests = true): void
    {
        $email = Str::lower($email);

        $taken = User::withTrashed()->whereRaw('lower(email) = ?', [$email])->exists()
            || $includePendingRequests && ApprovalRequest::query()->withoutGlobalScopes()
                ->where('status', ApprovalRequestStatus::Pending)
                ->where(fn ($query) => $query->where('payload->email', $email)->orWhere('payload->incoming_email', $email))
                ->exists();

        if ($taken) {
            throw new InvalidArgumentException('An account or pending request already uses this email address.');
        }
    }

    private function lockPending(ApprovalRequest $request): ApprovalRequest
    {
        $locked = ApprovalRequest::query()->withoutGlobalScopes()->whereKey($request->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== ApprovalRequestStatus::Pending) {
            throw new InvalidArgumentException('This request has already been decided.');
        }

        return $locked;
    }

    private function subjectOf(ApprovalRequest $request): User
    {
        return User::query()->find($request->subject_user_id)
            ?? throw new InvalidArgumentException('The account named in this request no longer exists.');
    }

    private function psRole(): Role
    {
        return Role::query()->where('name', AdministrationService::MINISTRY_PS)->firstOrFail();
    }

    private function describe(ApprovalRequestType $type): string
    {
        return match ($type) {
            ApprovalRequestType::PsAppointment => 'appointment',
            ApprovalRequestType::PsPromotion => 'promotion',
            ApprovalRequestType::PsDeactivation => 'deactivation',
            ApprovalRequestType::PsSuccession => 'succession',
        };
    }
}
