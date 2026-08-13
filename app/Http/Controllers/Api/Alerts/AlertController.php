<?php

namespace App\Http\Controllers\Api\Alerts;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Alerts\DelegateAlertRequest;
use App\Http\Requests\Api\Alerts\PostAlertFeedbackRequest;
use App\Http\Requests\Api\Alerts\StoreAlertAttachmentRequest;
use App\Http\Requests\Api\Alerts\StoreAlertRequest;
use App\Http\Requests\Api\Alerts\UpdateAlertRequest;
use App\Http\Resources\AlertDetailResource;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use App\Models\AlertAttachment;
use App\Services\AlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * API-001 Section 6 (Intelligence Alert Engine). Thin controller: every
 * mutation is delegated to AlertService (CLAUDE.md Section 11); ministry
 * scoping is applied automatically by the ministry.scope route middleware
 * plus the Alert model's global scope (Session 5).
 */
class AlertController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly AlertService $alertService) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Alert::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $alerts = Alert::query()
            ->with(['mission', 'submittedBy'])
            ->when($request->filled('mission_id'), fn ($query) => $query->where('mission_id', $request->string('mission_id')))
            ->when($request->filled('country'), fn ($query) => $query->where('country', $request->string('country')))
            ->when($request->filled('intelligence_type'), fn ($query) => $query->where('intelligence_type', $request->string('intelligence_type')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->string('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->string('date_to')))
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        return $this->respondWithData(
            AlertResource::collection($alerts->items()),
            meta: [
                'current_page' => $alerts->currentPage(),
                'per_page' => $alerts->perPage(),
                'total' => $alerts->total(),
                'last_page' => $alerts->lastPage(),
            ],
        );
    }

    public function store(StoreAlertRequest $request): JsonResponse
    {
        Gate::authorize('create', Alert::class);

        $alert = $this->alertService->submitAlert($request->validated(), $request->user());

        return $this->respondWithData(new AlertResource($alert->load(['mission', 'submittedBy'])), 201);
    }

    public function show(Alert $alert): JsonResponse
    {
        Gate::authorize('view', $alert);

        return $this->respondWithData(new AlertDetailResource(
            $alert->load(['mission', 'submittedBy', 'assignedTo', 'attachments', 'feedback.postedBy', 'versions'])
        ));
    }

    public function update(UpdateAlertRequest $request, Alert $alert): JsonResponse
    {
        Gate::authorize('update', $alert);

        $this->alertService->editAlert($alert, $request->validated(), $request->user());

        return $this->respondWithData(new AlertDetailResource(
            $alert->fresh(['mission', 'submittedBy', 'assignedTo', 'attachments', 'feedback.postedBy', 'versions'])
        ));
    }

    public function storeAttachment(StoreAlertAttachmentRequest $request, Alert $alert): JsonResponse
    {
        Gate::authorize('uploadAttachment', $alert);

        $file = $request->file('file');
        $path = $file->store("alerts/{$alert->id}", 'uploads');

        $attachment = AlertAttachment::create([
            'alert_id' => $alert->id,
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'file_size_bytes' => $file->getSize(),
            'mime_type' => $file->getClientMimeType(),
            'uploaded_by_user_id' => $request->user()->id,
        ]);

        return $this->respondWithData([
            'id' => $attachment->id,
            'original_filename' => $attachment->original_filename,
            'file_size_bytes' => $attachment->file_size_bytes,
            'mime_type' => $attachment->mime_type,
            'created_at' => $attachment->created_at,
        ], 201);
    }

    public function delegate(DelegateAlertRequest $request, Alert $alert): JsonResponse
    {
        Gate::authorize('delegate', $alert);

        $this->alertService->delegateAlert($alert, $request->input('delegate_user_ids'), $request->user());

        return $this->respondWithData(new AlertDetailResource(
            $alert->fresh(['mission', 'submittedBy', 'assignedTo', 'attachments', 'feedback.postedBy', 'versions'])
        ));
    }

    public function acknowledge(Request $request, Alert $alert): JsonResponse
    {
        Gate::authorize('acknowledge', $alert);

        $this->alertService->acknowledgeAlert($alert, $request->user());

        return $this->respondWithData(new AlertDetailResource(
            $alert->fresh(['mission', 'submittedBy', 'assignedTo', 'attachments', 'feedback.postedBy', 'versions'])
        ));
    }

    public function postFeedback(PostAlertFeedbackRequest $request, Alert $alert): JsonResponse
    {
        Gate::authorize('postFeedback', $alert);

        $feedback = $this->alertService->postFeedback($alert, $request->validated('content'), $request->user());

        return $this->respondWithData([
            'id' => $feedback->id,
            'content' => $feedback->content,
            'posted_by_user_id' => $feedback->posted_by_user_id,
            'created_at' => $feedback->created_at,
        ], 201);
    }
}
