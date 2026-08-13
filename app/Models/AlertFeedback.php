<?php

namespace App\Models;

use App\Models\Concerns\HasMinistryScope;
use Database\Factories\AlertFeedbackFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertFeedback extends Model
{
    /** @use HasFactory<AlertFeedbackFactory> */
    use HasFactory, HasMinistryScope, HasUuids;

    protected $fillable = [
        'alert_id',
        'posted_by_user_id',
        'content',
    ];

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

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }
}
