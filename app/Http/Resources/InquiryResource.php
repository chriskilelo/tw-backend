<?php

namespace App\Http\Resources;

use App\Models\Inquiry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * List/summary shape for GET /inquiries (FR-INQ-015, FR-INQ-018).
 *
 * @mixin Inquiry
 */
class InquiryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            'category' => $this->category,
            'sub_type' => $this->sub_type,
            'inquirer_name' => $this->inquirer_name,
            'product_or_sector' => $this->product_or_sector,
            'status' => $this->status,
            'high_value_flag' => $this->high_value_flag,
            'date_received' => $this->date_received,
            'mission' => $this->whenLoaded('mission', fn () => [
                'id' => $this->mission->id,
                'name' => $this->mission->name,
            ]),
            'logged_by' => $this->whenLoaded('loggedBy', fn () => [
                'id' => $this->loggedBy->id,
                'full_name' => $this->loggedBy->full_name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
