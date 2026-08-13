<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * FR-AUTH-007, FR-AUTH-009, FR-AUTH-012: authenticates against the
 * cookie-based 'web' session guard used by Sanctum SPA mode (TDD-ADR-002),
 * enforces the 5-failed-attempt account lockout, and logs every attempt.
 */
class LoginController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly AuditService $auditService) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        // withTrashed(): a deactivated account is soft-deleted (NFR-DATA-003),
        // but login must still resolve it to return the specific 403 below
        // rather than falling through to a generic "incorrect credentials" 401.
        $user = User::withTrashed()->where('email', $request->string('email'))->first();

        if ($user && $user->status === UserStatus::Locked) {
            $this->auditService->record(
                $user,
                'user.login.rejected_locked',
                User::class,
                $user->id,
                null,
                $request->ip(),
            );

            return $this->respondWithErrors(
                ['This account is locked. Reset your password or contact a System Administrator.'],
                423,
            );
        }

        if ($user && $user->status === UserStatus::Deactivated) {
            $this->auditService->record(
                $user,
                'user.login.rejected_deactivated',
                User::class,
                $user->id,
                null,
                $request->ip(),
            );

            return $this->respondWithErrors(
                ['This account has been deactivated. Contact a System Administrator.'],
                403,
            );
        }

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            if ($user) {
                $this->registerFailedAttempt($user, $request->ip());
            }

            return $this->respondWithErrors(['The provided credentials are incorrect.'], 401);
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'last_login_at' => now(),
        ])->save();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $this->auditService->record(
            $user,
            'user.login.succeeded',
            User::class,
            $user->id,
            null,
            $request->ip(),
        );

        return $this->respondWithData(['user' => $this->presentUser($user)]);
    }

    private function registerFailedAttempt(User $user, ?string $ip): void
    {
        $attempts = $user->failed_login_attempts + 1;
        $becomesLocked = $attempts >= 5;

        $user->forceFill([
            'failed_login_attempts' => $attempts,
            'status' => $becomesLocked ? UserStatus::Locked : $user->status,
        ])->save();

        $this->auditService->record(
            $user,
            $becomesLocked ? 'user.login.failed_account_locked' : 'user.login.failed',
            User::class,
            $user->id,
            ['failed_login_attempts' => $attempts],
            $ip,
        );
    }

    /**
     * Session 24 (Stage 1 Gate): shape corrected to match tw-frontend's
     * AuthUser contract (src/api/auth.ts) — the same fix as MeController's
     * presentUser(), for consistency. LoginPage.tsx currently ignores this
     * response body (it refetches GET /me after login instead), so this
     * mismatch was latent rather than an observed bug, but LoginResponse is
     * still typed as `{ user: AuthUser }` and should actually satisfy that.
     *
     * @return array<string, mixed>
     */
    private function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'mission_id' => $user->mission_id,
            'ministry_id' => $user->ministry_id,
            'status' => $user->status,
            'language_preference' => $user->language_preference,
            'email_notification_preferences' => $user->email_notification_preferences,
        ];
    }
}
