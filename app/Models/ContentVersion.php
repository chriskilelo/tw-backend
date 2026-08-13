<?php

namespace App\Models;

use App\Models\Concerns\HasMinistryScope;
use Database\Factories\ContentVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentVersion extends Model
{
    /** @use HasFactory<ContentVersionFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'content_item_id',
        'version_number',
        'snapshot',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'snapshot' => 'array',
        ];
    }

    public function ministryScopeColumn(): ?string
    {
        return null;
    }

    public function ministryScopeRelation(): ?string
    {
        return 'contentItem';
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
