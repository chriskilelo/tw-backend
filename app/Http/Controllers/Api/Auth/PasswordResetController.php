<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

/**
 * FR-AUTH-011: request/complete a password reset. The completion path also
 * restores a locked account to Active and resets the failed-attempt
 * counter (FR-AUTH-009 acceptance criterion 2).
 */
class PasswordResetController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly AuditService $auditService) {}

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::broker('users')->sendResetLink($request->only('email'));

        // Always return the same generic response regardless of whether the
        // email is registered, so this endpoint cannot be used to enumerate
        // accounts (FR-AUTH-007 AC2 principle applied to password reset).
        return $this->respondWithData([
            'message' => 'If that email address is registered, a password reset link has been sent.',
        ]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker('users')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($request): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'status' => UserStatus::Active,
                    'failed_login_attempts' => 0,
                ])->save();

                DB::table('sessions')->where('user_id', $user->id)->delete();

                $this->auditService->record(
                    $user,
                    'user.password_reset.completed',
                    User::class,
                    $user->id,
                    null,
                    $request->ip(),
                );
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return $this->respondWithErrors([__($status)], 422);
        }

        return $this->respondWithData(['message' => __($status)]);
    }
}
