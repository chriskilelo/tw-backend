<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreUserRequest;
use App\Http\Requests\Api\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Jobs\SendAccountActivationEmail;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * System Administrator-only account management (session 07 task 1).
 * Authorisation is enforced per-endpoint via UserPolicy, not by hiding
 * frontend controls (CLAUDE.md Section 10).
 */
class UserController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly AuditService $auditService) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $users = User::query()
            ->with(['role', 'mission', 'ministry'])
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

        $user = User::create([
            'full_name' => $request->string('full_name'),
            'email' => $request->string('email'),
            'password' => Str::random(40),
            'role_id' => $request->input('role_id'),
            'mission_id' => $request->input('mission_id'),
            'ministry_id' => $request->input('ministry_id'),
            'status' => UserStatus::ActivationPending,
        ]);

        $this->auditService->record(
            $request->user(),
            'user.created',
            User::class,
            $user->id,
            $request->only(['full_name', 'email', 'role_id', 'mission_id', 'ministry_id']),
            $request->ip(),
        );

        $this->dispatchActivationEmail($user);

        return $this->respondWithData(new UserResource($user->load(['role', 'mission', 'ministry'])), 201);
    }

    public function show(User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return $this->respondWithData(new UserResource($user->load(['role', 'mission', 'ministry'])));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        Gate::authorize('update', $user);

        $changes = $request->only(['full_name', 'email', 'role_id', 'mission_id', 'ministry_id']);

        $user->forceFill($changes)->save();

        $this->auditService->record(
            $request->user(),
            'user.updated',
            User::class,
            $user->id,
            $changes,
            $request->ip(),
        );

        return $this->respondWithData(new UserResource($user->fresh(['role', 'mission', 'ministry'])));
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        Gate::authorize('deactivate', $user);

        $user->forceFill(['status' => UserStatus::Deactivated])->save();

        // NFR-DATA-003: soft delete, never a hard delete; BR-002 requires
        // every historical record the user authored to keep its original
        // authorship attribution, which SoftDeletes preserves.
        $user->delete();

        DB::table('sessions')->where('user_id', $user->id)->delete();

        $this->auditService->record($request->user(), 'user.deactivated', User::class, $user->id, null, $request->ip());

        return $this->respondWithData(new UserResource($user->fresh(['role', 'mission', 'ministry'])));
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        Gate::authorize('reactivate', $user);

        if ($user->status !== UserStatus::Deactivated) {
            return $this->respondWithErrors(['Only a deactivated account can be reactivated.'], 422);
        }

        $user->restore();

        $user->forceFill([
            'status' => UserStatus::Active,
            'failed_login_attempts' => 0,
        ])->save();

        $this->auditService->record($request->user(), 'user.reactivated', User::class, $user->id, null, $request->ip());

        return $this->respondWithData(new UserResource($user->fresh(['role', 'mission', 'ministry'])));
    }

    public function resendActivation(Request $request, User $user): JsonResponse
    {
        Gate::authorize('resendActivation', $user);

        $this->dispatchActivationEmail($user);

        $this->auditService->record(
            $request->user(),
            'user.activation_email.resent',
            User::class,
            $user->id,
            null,
            $request->ip(),
        );

        return $this->respondWithData(['message' => 'Activation email resent.']);
    }

    private function dispatchActivationEmail(User $user): void
    {
        $token = Password::broker('users')->createToken($user);

        SendAccountActivationEmail::dispatch($user->email, $token);
    }
}
