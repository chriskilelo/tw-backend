<?php

namespace App\Models;

use App\Models\Concerns\HasMinistryScope;
use App\Models\Concerns\IsAppendOnly;
use Database\Factories\AlertVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertVersion extends Model
{
    /** @use HasFactory<AlertVersionFactory> */
    use HasFactory, HasMinistryScope, HasUuids, IsAppendOnly;

    const UPDATED_AT = null;

    protected $fillable = [
        'alert_id',
        'snapshot',
        'edited_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }

    public function ministryScopeColumn(): ?string
    {
        return null;
    }

    public function ministryScopeRelation(): ?string
    {
        return 'alert';
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_user_id');
    }
}
