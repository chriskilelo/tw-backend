<?php

namespace App\Models;

use App\Models\Concerns\HasMinistryScope;
use Database\Factories\AlertAttachmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertAttachment extends Model
{
    /** @use HasFactory<AlertAttachmentFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'alert_id',
        'file_path',
        'original_filename',
        'file_size_bytes',
        'mime_type',
        'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
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

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
