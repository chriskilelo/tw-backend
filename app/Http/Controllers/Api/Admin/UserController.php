<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreUserRequest;
use App\Http\Requests\Api\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Services\AdministrationService;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Account management (session 07 task 1; ADR-006 / FR-AUTH-021).
 * Authorisation is enforced per-endpoint via UserPolicy, not by hiding
 * frontend controls (CLAUDE.md Section 10).
 *
 * A Ministry Administrator works within its own department only: `users`
 * carries no ministry global scope, so the listing is filtered explicitly,
 * and any account outside the department returns 404 (never 403), so its
 * existence is not confirmed. Seat rules (BR-026, BR-028, BR-029) run inside
 * the same transaction as the write, after locking the department row.
 */
class UserController extends Controller
{
    use ApiResponds;

    private const array RELATIONS = ['role', 'mission', 'ministry', 'homeMinistry'];

    public function __construct(
        private readonly AuditService $auditService,
        private readonly AdministrationService $administrationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);
        $ministryId = AdministrationService::listingMinistryId($request->user(), $request->input('ministry'));

        $users = User::query()
            ->with(self::RELATIONS)
            ->when($ministryId !== null, fn ($query) => $query->where('ministry_id', $ministryId))
            ->when(
                AdministrationService::isMinistryAdministrator($request->user()),
                fn ($query) => $query->whereNotIn('role_id', Role::query()->whereIn('name', BasePolicy::READ_ONLY_ROLES)->select('id')),
            )
            ->when($request->filled('role'), fn ($query) => $query->where('role_id', $request->string('role')))
            ->when($request->filled('mission'), fn ($query) => $query->where('mission_id', $request->string('mission')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('full_name')
            ->paginate($perPage)
            ->withQueryString();

        return $this->respondWithData(
            UserResource::collection($users->items()),
            meta: [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        Gate::authorize('create', User::class);

        $actor = $request->user();
        $role = Role::query()->findOrFail($request->input('role_id'));
        $ministryId = AdministrationService::isMinistryAdministrator($actor) ? $actor->ministry_id : $request->input('ministry_id');

        try {
            $user = DB::transaction(function () use ($request, $role, $ministryId): User {
                $this->assertSeatAvailable($role, $ministryId);

                return User::create([
                    'full_name' => $request->string('full_name'),
                    'email' => $request->string('email'),
                    'password' => Str::random(40),
                    'role_id' => $role->id,
                    'mission_id' => $request->input('mission_id'),
                    'ministry_id' => $ministryId,
                    'home_ministry_id' => $request->input('home_ministry_id'),
                    'status' => UserStatus::ActivationPending,
                ]);
            });
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        $this->auditService->record(
            $actor,
            'user.created',
            User::class,
            $user->id,
            [...$request->only(['full_name', 'email', 'role_id', 'mission_id', 'home_ministry_id']), 'ministry_id' => $ministryId],
            $request->ip(),
            $ministryId,
        );

        $this->administrationService->sendActivationEmail($user, $request->user());

        return $this->respondWithData(new UserResource($user->load(self::RELATIONS)), 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->abortUnlessWithinDepartment($request->user(), $user);
        Gate::authorize('view', $user);

        return $this->respondWithData(new UserResource($user->load(self::RELATIONS)));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->abortUnlessWithinDepartment($request->user(), $user);
        Gate::authorize('update', $user);

        $changes = $request->only(['full_name', 'email', 'role_id', 'mission_id', 'ministry_id', 'home_ministry_id']);

        $resultingRole = isset($changes['role_id']) ? Role::query()->find($changes['role_id']) : $user->role;
        if (in_array($resultingRole?->name, BasePolicy::READ_ONLY_ROLES, true) && $user->ministry_id !== null) {
            $changes['ministry_id'] = null;
        }

        try {
            DB::transaction(function () use ($user, $changes): void {
                if (isset($changes['role_id']) && $changes['role_id'] !== $user->role_id) {
                    $this->administrationService->assertNotLastSystemAdministrator($user);
                    $this->assertSeatAvailable(Role::query()->findOrFail($changes['role_id']), $changes['ministry_id'] ?? $user->ministry_id, $user);
                }

                $user->forceFill($changes)->save();
            });
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        $this->auditService->record(
            $request->user(),
            'user.updated',
            User::class,
            $user->id,
            $changes,
            $request->ip(),
            $user->ministry_id,
        );

        return $this->respondWithData(new UserResource($user->fresh(self::RELATIONS)));
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $this->abortUnlessWithinDepartment($actor, $user);
        Gate::authorize('deactivate', $user);

        if ($user->is($actor)) {
            return $this->respondWithErrors(['You cannot deactivate your own account.'], 422);
        }

        if (AdministrationService::isMinistryAdministrator($actor) && $user->role?->name === AdministrationService::MINISTRY_PS) {
            return $this->respondWithErrors(['Deactivating a Principal Secretary requires System Administrator approval. Submit a PS deactivation request (BR-027).'], 422);
        }

        try {
            DB::transaction(function () use ($user, $actor, $request): void {
                $this->administrationService->assertNotLastSystemAdministrator($user);
                $this->administrationService->deactivateUser($user, $actor, $request->ip());
            });
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        return $this->respondWithData(new UserResource($user->fresh(self::RELATIONS)));
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $this->abortUnlessWithinDepartment($actor, $user);
        Gate::authorize('reactivate', $user);

        if ($user->status !== UserStatus::Deactivated) {
            return $this->respondWithErrors(['Only a deactivated account can be reactivated.'], 422);
        }

        if (AdministrationService::isMinistryAdministrator($actor) && $user->role?->name === AdministrationService::MINISTRY_PS) {
            return $this->respondWithErrors(['Reinstating a Principal Secretary requires System Administrator approval (BR-027).'], 422);
        }

        try {
            DB::transaction(function () use ($user): void {
                $this->assertSeatAvailable($user->role, $user->ministry_id, $user);

                $user->restore();

                $user->forceFill([
                    'status' => UserStatus::Active,
                    'failed_login_attempts' => 0,
                ])->save();
            });
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        $this->auditService->record($actor, 'user.reactivated', User::class, $user->id, null, $request->ip(), $user->ministry_id);

        return $this->respondWithData(new UserResource($user->fresh(self::RELATIONS)));
    }

    public function resendActivation(Request $request, User $user): JsonResponse
    {
        $this->abortUnlessWithinDepartment($request->user(), $user);
        Gate::authorize('resendActivation', $user);

        $this->administrationService->sendActivationEmail($user, $request->user());

        $this->auditService->record(
            $request->user(),
            'user.activation_email.resent',
            User::class,
            $user->id,
            null,
            $request->ip(),
            $user->ministry_id,
        );

        return $this->respondWithData(['message' => 'Activation email resent.']);
    }

    /**
     * IDOR guard (same pattern as NotificationController::markRead()): an
     * account outside the actor's department — a mission-governance account
     * included — is reported as not found.
     */
    private function abortUnlessWithinDepartment(User $actor, User $target): void
    {
        abort_unless(AdministrationService::canAdministerAccount($actor, $target), 404);
    }

    /**
     * BR-026 / BR-028 for the role an account is being given. Must run inside
     * the caller's transaction; locks the department row first.
     */
    private function assertSeatAvailable(?Role $role, ?string $ministryId, ?User $excluding = null): void
    {
        if ($ministryId === null || ! in_array($role?->name, [AdministrationService::MINISTRY_ADMINISTRATOR, AdministrationService::MINISTRY_PS], true)) {
            return;
        }

        $this->administrationService->lockMinistry($ministryId);

        if ($role->name === AdministrationService::MINISTRY_ADMINISTRATOR) {
            $this->administrationService->assertMinistryAdministratorCapacity($ministryId, $excluding);

            return;
        }

        $this->administrationService->assertPsVacancy($ministryId, $excluding);
    }
}
