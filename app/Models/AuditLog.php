<?php

namespace App\Models;

use App\Models\Concerns\IsAppendOnly;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory, HasUuids, IsAppendOnly;

    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'ministry_id',
        'action',
        'affected_entity_type',
        'affected_entity_id',
        'changes',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function affectedEntity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'affected_entity_type', 'affected_entity_id');
    }
}
