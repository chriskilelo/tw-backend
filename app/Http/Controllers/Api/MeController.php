<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAvatarRequest;
use App\Http\Requests\Api\UpdatePreferencesRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MeController extends Controller
{
    use ApiResponds;

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing(['mission', 'ministry']);

        return $this->respondWithData($this->presentUser($user));
    }

    public function updatePreferences(UpdatePreferencesRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->forceFill($request->only([
            'language_preference',
            'email_notification_preferences',
        ]))->save();

        $user->loadMissing(['mission', 'ministry']);

        return $this->respondWithData($this->presentUser($user));
    }

    /**
     * FR-AUTH-019: replaces any existing photo — the old file is deleted from disk first
     * so a user who changes their photo repeatedly never leaves orphaned files behind
     * (BR-024: removal/replacement never touches any other record, only this file).
     */
    public function uploadAvatar(StoreAvatarRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $file = $request->file('file');

        if ($user->avatar_path) {
            Storage::disk('uploads')->delete($user->avatar_path);
        }

        // NFR-SEC-004 pattern (Session 38): UUID filename, never the client's original
        // name — see StoreAlertAttachmentRequest's identical reasoning.
        $filename = Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs("avatars/{$user->id}", $filename, 'uploads');

        $user->forceFill(['avatar_path' => $path])->save();
        $user->loadMissing(['mission', 'ministry']);

        return $this->respondWithData($this->presentUser($user));
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('uploads')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }

        return response()->json(null, 204);
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
                // FR-AUTH-019: signed, time-limited URL (NFR-SEC-004 pattern, same
                // mechanism as AlertController::downloadAttachment()) — never a
                // permanent public path. Null when no photo has been uploaded; the
                // frontend falls back to an initials-based avatar (BR-024, AC2).
                'avatar_url' => $user->avatar_path
                    ? Storage::disk('uploads')->temporaryUrl($user->avatar_path, now()->addMinutes(15))
                    : null,
                // Additive for ProfilePage (My Profile): GET /me previously only exposed the
                // raw FK ids, so the frontend had no way to show "London Mission" / "State
                // Department for Trade" without a second lookup. Nullable since System
                // Administrator / MFA-scoped roles carry neither (CLAUDE.md Section 6).
                'mission' => $user->relationLoaded('mission') && $user->mission
                    ? [
                        'id' => $user->mission->id,
                        'name' => $user->mission->name,
                        'host_country' => $user->mission->host_country,
                        // The dashboard's mission clock: the user's own posting, so
                        // this does not widen GET /missions' administrator-only time_zone.
                        'city' => $user->mission->city,
                        'time_zone' => $user->mission->time_zone,
                    ]
                    : null,
                'ministry' => $user->relationLoaded('ministry') && $user->ministry
                    ? ['id' => $user->ministry->id, 'name' => $user->ministry->name]
                    : null,
                // ADR-006: display-only home department of a System Administrator.
                'home_ministry' => $user->home_ministry_id !== null && $user->homeMinistry
                    ? ['id' => $user->homeMinistry->id, 'name' => $user->homeMinistry->name]
                    : null,
            ],
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
                'layer' => $user->role->layer,
                'scope' => $user->role->scope,
                // FR-AUTH-025: cosmetic title only, never used for authorisation.
                'display_title' => $user->role->display_title,
            ] : null,
            'permissions' => $user->role
                ? $user->role->permissions()->pluck('permission_key')->all()
                : [],
        ];
    }
}
