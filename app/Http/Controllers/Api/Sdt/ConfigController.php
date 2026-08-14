<?php

namespace App\Http\Controllers\Api\Sdt;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Sdt\StoreAieBudgetCodeRequest;
use App\Http\Requests\Api\Sdt\StoreAlertFieldRequest;
use App\Http\Requests\Api\Sdt\StoreDirectiveSettingRequest;
use App\Http\Requests\Api\Sdt\StoreInquirySettingRequest;
use App\Http\Requests\Api\Sdt\StoreReferralOrganisationRequest;
use App\Http\Requests\Api\Sdt\UpdateAieBudgetCodeRequest;
use App\Http\Requests\Api\Sdt\UpdateAlertFieldRequest;
use App\Http\Requests\Api\Sdt\UpdateInquirySettingRequest;
use App\Http\Requests\Api\Sdt\UpdateReferralOrganisationRequest;
use App\Models\MasterDataEntry;
use App\Models\ReferralOrganisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * FR-SDT-019, FR-SDT-020, FR-SDT-023: System-Administrator-only
 * administration screens, reusing the existing master_data_entries table
 * (alert fields, inquiry categories/statuses/event types — CLAUDE.md
 * Section 6/8) and the existing referral_organisations table rather than
 * inventing parallel config tables.
 *
 * Every action here is deliberately System Administrator only, including
 * GET — distinct from the public /master-data and /referral-organisations
 * endpoints (Session 12/13), which stay open to any authenticated user for
 * dropdown population. See MasterDataEntryPolicy::manage() /
 * ReferralPolicy::manage().
 */
class ConfigController extends Controller
{
    use ApiResponds;

    private const string ALERT_FIELD_CATEGORY = 'alert_intelligence_type';

    private const string AIE_BUDGET_CODE_CATEGORY = 'aie_budget_code';

    private const string DIRECTIVE_TYPE_CATEGORY = 'directive_type';

    /**
     * @var array<int, string>
     */
    public const array INQUIRY_SETTING_CATEGORIES = [
        'inquiry_category',
        'inquiry_workflow_status',
        'inquiry_event_type',
    ];

    // --- FR-SDT-019: Alert Field Configuration --------------------------

    public function alertFields(Request $request): JsonResponse
    {
        Gate::authorize('manage', MasterDataEntry::class);

        $entries = MasterDataEntry::query()
            ->where('category', self::ALERT_FIELD_CATEGORY)
            ->when($request->filled('ministry_id'), fn ($query) => $query->where('ministry_id', $request->string('ministry_id')))
            ->orderBy('display_order')
            ->orderBy('value')
            ->get();

        return $this->respondWithData($entries->map($this->presentEntry(...)));
    }

    public function storeAlertField(StoreAlertFieldRequest $request): JsonResponse
    {
        Gate::authorize('manage', MasterDataEntry::class);

        $entry = MasterDataEntry::create([
            ...$request->validated(),
            'category' => self::ALERT_FIELD_CATEGORY,
        ]);

        return $this->respondWithData($this->presentEntry($entry), 201);
    }

    public function updateAlertField(UpdateAlertFieldRequest $request, MasterDataEntry $masterDataEntry): JsonResponse
    {
        Gate::authorize('manage', MasterDataEntry::class);

        abort_if($masterDataEntry->category !== self::ALERT_FIELD_CATEGORY, 404);

        $masterDataEntry->fill($request->validated())->save();

        return $this->respondWithData($this->presentEntry($masterDataEntry->fresh()));
    }

    // --- FR-SDT-022: AIE Budget Code Configuration ----------------------

    /**
     * The 19 AIE budget code rows (CLAUDE.md Section 8) start as seeded
     * data but are editable going forward — SDT's own budget lines change
     * across financial years (FR-RPT-008 versioning note), so this screen
     * is now a full list/store/update triple, matching the alert-field
     * pattern above rather than staying strictly read-only.
     */
    public function aieBudgetCodes(Request $request): JsonResponse
    {
        Gate::authorize('manage', MasterDataEntry::class);

        $entries = MasterDataEntry::query()
            ->where('category', self::AIE_BUDGET_CODE_CATEGORY)
            ->when($request->filled('ministry_id'), fn ($query) => $query->where('ministry_id', $request->string('ministry_id')))
            ->orderBy('display_order')
            ->orderBy('value')
            ->get();

        return $this->respondWithData($entries->map($this->presentEntry(...)));
    }

    public function storeAieBudgetCode(StoreAieBudgetCodeRequest $request): JsonResponse
    {
        Gate::authorize('manage', MasterDataEntry::class);

        $entry = MasterDataEntry::create([
            ...$request->validated(),
            'category' => self::AIE_BUDGET_CODE_CATEGORY,
        ]);

        return $this->respondWithData($this->presentEntry($entry), 201);
    }

    public function updateAieBudgetCode(UpdateAieBudgetCodeRequest $request, MasterDataEntry $masterDataEntry): JsonResponse
    {
        Gate::authorize('manage', MasterDataEntry::class);

        abort_if($masterDataEntry->category !== self::AIE_BUDGET_CODE_CATEGORY, 404);

        $masterDataEntry->fill($request->validated())->save();

        return $this->respondWithData($this->presentEntry($masterDataEntry->fresh()));
    }

    // --- FR-SDT-010: Directive Type Configuration -----------------------

    /**
     * CLAUDE.md Section 8: the directive type field has "no fixed category
     * list configured at go-live" — this endpoint lets a System
     * Administrator start populating the 'directive_type'
     * master_data_entries category as SDT defines categorisations over
     * time (DirectiveService/StoreDirectiveRequest already accept
     * type_category as free text regardless of whether it is configured
     * here).
     */
    public function storeDirectiveSetting(StoreDirectiveSettingRequest $request): JsonResponse
    {
        Gate::authorize('manage', MasterDataEntry::class);

        $entry = MasterDataEntry::create([
            ...$request->validated(),
            'category' => self::DIRECTIVE_TYPE_CATEGORY,
        ]);

        return $this->respondWithData($this->presentEntry($entry), 201);
    }

    // --- FR-SDT-020: Inquiry Category and Status Configuration ----------

    public function inquirySettings(Request $request): JsonResponse
    {
        Gate::authorize('manage', MasterDataEntry::class);

        $entries = MasterDataEntry::query()
            ->whereIn('category', self::INQUIRY_SETTING_CATEGORIES)
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
            ->when($request->filled('ministry_id'), fn ($query) => $query->where('ministry_id', $request->string('ministry_id')))
            ->orderBy('category')
            ->orderBy('display_order')
            ->orderBy('value')
            ->get();

        return $this->respondWithData($entries->map($this->presentEntry(...)));
    }

    public function storeInquirySetting(StoreInquirySettingRequest $request): JsonResponse
    {
        Gate::authorize('manage', MasterDataEntry::class);

        $entry = MasterDataEntry::create($request->validated());

        return $this->respondWithData($this->presentEntry($entry), 201);
    }

    public function updateInquirySetting(UpdateInquirySettingRequest $request, MasterDataEntry $masterDataEntry): JsonResponse
    {
        Gate::authorize('manage', MasterDataEntry::class);

        abort_if(! in_array($masterDataEntry->category, self::INQUIRY_SETTING_CATEGORIES, true), 404);

        $masterDataEntry->fill($request->validated())->save();

        return $this->respondWithData($this->presentEntry($masterDataEntry->fresh()));
    }

    // --- FR-SDT-023: Referral Organisation Registry ----------------------

    public function referralOrganisations(Request $request): JsonResponse
    {
        Gate::authorize('manage', ReferralOrganisation::class);

        $organisations = ReferralOrganisation::query()
            ->when($request->filled('ministry_id'), fn ($query) => $query->where('ministry_id', $request->string('ministry_id')))
            ->when($request->filled('active'), fn ($query) => $query->where('active', $request->boolean('active')))
            ->orderBy('name')
            ->get();

        return $this->respondWithData($organisations->map($this->presentOrganisation(...)));
    }

    public function storeReferralOrganisation(StoreReferralOrganisationRequest $request): JsonResponse
    {
        Gate::authorize('manage', ReferralOrganisation::class);

        $organisation = ReferralOrganisation::create($request->validated());

        return $this->respondWithData($this->presentOrganisation($organisation), 201);
    }

    public function updateReferralOrganisation(UpdateReferralOrganisationRequest $request, ReferralOrganisation $referralOrganisation): JsonResponse
    {
        Gate::authorize('manage', ReferralOrganisation::class);

        $referralOrganisation->fill($request->validated())->save();

        return $this->respondWithData($this->presentOrganisation($referralOrganisation->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentEntry(MasterDataEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'ministry_id' => $entry->ministry_id,
            'category' => $entry->category,
            'value' => $entry->value,
            'display_order' => $entry->display_order,
            'active' => $entry->active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentOrganisation(ReferralOrganisation $organisation): array
    {
        return [
            'id' => $organisation->id,
            'ministry_id' => $organisation->ministry_id,
            'name' => $organisation->name,
            'active' => $organisation->active,
        ];
    }
}
