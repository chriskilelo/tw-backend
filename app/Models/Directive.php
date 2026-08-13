<?php

namespace App\Models;

use App\Enums\DirectiveStatus;
use App\Models\Concerns\HasMinistryScope;
use Database\Factories\DirectiveFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Directive extends Model
{
    /** @use HasFactory<DirectiveFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    protected $fillable = [
        'ministry_id',
        'mission_id',
        'target_user_id',
        'issued_by_user_id',
        'type_category',
        'description',
        'target_completion_date',
        'status',
        'completion_summary',
        'last_progress_update_at',
    ];

    protected function casts(): array
    {
        return [
            'target_completion_date' => 'date',
            'status' => DirectiveStatus::class,
            'last_progress_update_at' => 'datetime',
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

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(DirectiveNote::class);
    }
}
