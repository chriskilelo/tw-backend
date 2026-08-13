<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-NOTIF-001 to 004: a user's own in-app notifications. Always scoped to
 * the authenticated user, the same pattern MeController uses — there is no
 * cross-user access path here for a policy to guard.
 */
class NotificationController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->integer('per_page', 25), 100);

        $notifications = Notification::query()
            ->where('recipient_user_id', $request->user()->id)
            ->when($request->filled('read'), fn ($query) => $request->boolean('read')
                ? $query->whereNotNull('read_at')
                : $query->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        return $this->respondWithData(
            NotificationResource::collection($notifications->items()),
            meta: [
                'current_page' => $notifications->currentPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'last_page' => $notifications->lastPage(),
            ],
        );
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->recipient_user_id === $request->user()->id, 404);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $this->respondWithData(new NotificationResource($notification));
    }

    public function markAllRead(Request $request): JsonResponse
    {
        Notification::query()
            ->where('recipient_user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->respondWithData(['message' => 'All notifications marked read.']);
    }
}
