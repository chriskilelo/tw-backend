<?php

namespace App\Http\Controllers\Api\Referrals;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Referrals\StoreReferralAttachmentRequest;
use App\Http\Requests\Api\Referrals\StoreReferralRequest;
use App\Http\Resources\ReferralResource;
use App\Models\Inquiry;
use App\Models\ReferralAttachment;
use App\Models\ReferralEntry;
use App\Models\ReferralOrganisation;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * API-001 (Referral Register Engine), FR-REF-001 to 006. Thin controller:
 * every mutation is delegated to ReferralService (CLAUDE.md Section 11);
 * ministry scoping is applied automatically by the ministry.scope route
 * middleware plus ReferralEntry/ReferralOrganisation's global scope.
 */
class ReferralController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly ReferralService $referralService) {}

    public function indexOrganisations(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ReferralOrganisation::class);

        $organisations = ReferralOrganisation::query()
            ->when($request->filled('active'), fn ($query) => $query->where('active', $request->boolean('active')))
            ->orderBy('name')
            ->get();

        return $this->respondWithData($organisations->map(fn (ReferralOrganisation $organisation) => [
            'id' => $organisation->id,
            'name' => $organisation->name,
            'active' => $organisation->active,
        ]));
    }

    public function store(StoreReferralRequest $request, Inquiry $inquiry): JsonResponse
    {
        Gate::authorize('create', ReferralEntry::class);

        $organisation = ReferralOrganisation::findOrFail($request->validated('referral_organisation_id'));

        $referral = $this->referralService->recordReferral(
            $inquiry,
            $organisation,
            $request->validated(),
            $request->user(),
        );

        return $this->respondWithData(
            new ReferralResource($referral->load(['referralOrganisation', 'createdBy'])),
            201,
        );
    }

    public function storeAttachment(StoreReferralAttachmentRequest $request, ReferralEntry $referralEntry): JsonResponse
    {
        Gate::authorize('uploadAttachment', $referralEntry);

        $file = $request->file('file');

        // NFR-SEC-004 (Session 38): UUID filename, never the client
        // original — see AlertController::storeAttachment()'s identical
        // reasoning.
        $filename = Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs("referrals/{$referralEntry->id}", $filename, 'uploads');

        $attachment = ReferralAttachment::create([
            'referral_entry_id' => $referralEntry->id,
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

    /**
     * NFR-SEC-004 (Session 38): see
     * AlertController::downloadAttachment()'s identical reasoning — gated
     * the same as viewing the parent referral entry
     * (ReferralPolicy::view() is open to any ministry-scoped user).
     */
    public function downloadAttachment(ReferralEntry $referralEntry, ReferralAttachment $attachment): JsonResponse
    {
        Gate::authorize('view', $referralEntry);

        abort_unless($attachment->referral_entry_id === $referralEntry->id, 404);

        $expiresAt = now()->addMinutes(15);

        return $this->respondWithData([
            'url' => Storage::disk('uploads')->temporaryUrl($attachment->file_path, $expiresAt),
            'expires_at' => $expiresAt,
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('viewSummary', ReferralEntry::class);

        $summary = $this->referralService->getReferralSummary($request->only([
            'organisation_id', 'mission_id', 'date_from', 'date_to',
        ]));

        return $this->respondWithData($summary);
    }
}
