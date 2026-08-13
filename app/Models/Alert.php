<?php

namespace App\Models;

use App\Enums\AlertStatus;
use App\Models\Concerns\HasMinistryScope;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    protected $fillable = [
        'ministry_id',
        'mission_id',
        'submitted_by_user_id',
        'reference_number',
        'country',
        'sector',
        'product_category',
        'product_description',
        'intelligence_type',
        'intelligence_source',
        'urgency',
        'confidence_rating',
        'tags',
        'status',
        'assigned_to_user_id',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'status' => AlertStatus::class,
        ];
    }

    public function ministry(): BelongsTo
    {
        return $this->belongsTo(Ministry::class);
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AlertVersion::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(AlertFeedback::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AlertAttachment::class);
    }
}
