<?php

namespace App\Models;

use Database\Factories\MasterDataEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MasterDataEntry extends Model
{
    /** @use HasFactory<MasterDataEntryFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'ministry_id',
        'category',
        'value',
        'display_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function ministry(): BelongsTo
    {
        return $this->belongsTo(Ministry::class);
    }
}
