<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdatePreferencesRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    use ApiResponds;

    public function show(Request $request): JsonResponse
    {
        return $this->respondWithData($this->presentUser($request->user()));
    }

    public function updatePreferences(UpdatePreferencesRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->forceFill($request->only([
            'language_preference',
            'email_notification_preferences',
        ]))->save();

        return $this->respondWithData($this->presentUser($user));
    }

    /**
     * Session 24 (Stage 1 Gate): this used to return a flat structure with
     * `role` as a bare string, which does not match tw-frontend's MeResponse
     * contract (src/api/auth.ts / useAuth.ts expect `{ user, role, permissions }`,
     * with `role` an object carrying `name`). Every existing frontend page reads
     * `role?.name` and `user?.id` against that nested shape, and every Vitest
     * mock across the suite already assumes it — this was the one real
     * inconsistency, surfaced only once an actual browser E2E run authenticated
     * and rendered a page that branches on `role.name` (PsDashboardPage's
     * delegate control). Fixing here rather than in the frontend, since the
     * blast radius of the flat shape spans a single controller versus dozens of
     * already-built, already-tested pages.
     *
     * @return array{user: array<string, mixed>, role: array<string, mixed>|null, permissions: array<int, string>}
     */
    private function presentUser(User $user): array
    {
        return [
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'role_id' => $user->role_id,
                'mission_id' => $user->mission_id,
                'ministry_id' => $user->ministry_id,
                'status' => $user->status,
                'language_preference' => $user->language_preference,
                'email_notification_preferences' => $user->email_notification_preferences,
            ],
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
                'layer' => $user->role->layer,
                'scope' => $user->role->scope,
            ] : null,
            'permissions' => $user->role
                ? $user->role->permissions()->pluck('permission_key')->all()
                : [],
        ];
    }
}
