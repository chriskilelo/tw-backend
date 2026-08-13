<?php

namespace App\Models;

use App\Enums\ClassificationLevel;
use App\Enums\ContentStatus;
use App\Models\Concerns\HasMinistryScope;
use Database\Factories\ContentItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentItem extends Model
{
    /** @use HasFactory<ContentItemFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    protected $fillable = [
        'ministry_id',
        'created_by_user_id',
        'title',
        'category',
        'classification_level',
        'body',
        'status',
        'approved_by_user_id',
        'approved_at',
        'rejection_comment',
        'publication_ready',
        'public_version_of_content_item_id',
    ];

    protected function casts(): array
    {
        return [
            'classification_level' => ClassificationLevel::class,
            'status' => ContentStatus::class,
            'approved_at' => 'datetime',
            'publication_ready' => 'boolean',
        ];
    }

    public function ministry(): BelongsTo
    {
        return $this->belongsTo(Ministry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function publicVersionOf(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class, 'public_version_of_content_item_id');
    }

    public function publicVersions(): HasMany
    {
        return $this->hasMany(ContentItem::class, 'public_version_of_content_item_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContentVersion::class);
    }
}
