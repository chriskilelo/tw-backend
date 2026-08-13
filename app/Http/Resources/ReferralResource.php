<?php

namespace App\Http\Resources;

use App\Models\ReferralEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FR-REF-002, FR-REF-006: referral entry shape returned on creation and
 * within an inquiry's chronological referral history.
 *
 * @mixin ReferralEntry
 */
class ReferralResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inquiry_id' => $this->inquiry_id,
            'referral_organisation' => $this->whenLoaded('referralOrganisation', fn () => [
                'id' => $this->referralOrganisation->id,
                'name' => $this->referralOrganisation->name,
            ]),
            'contact_person' => $this->contact_person,
            'referral_date' => $this->referral_date,
            'referral_method' => $this->referral_method,
            'reference_number' => $this->reference_number,
            'remarks' => $this->remarks,
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy->id,
                'full_name' => $this->createdBy->full_name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
