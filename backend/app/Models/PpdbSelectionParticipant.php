<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Keikutsertaan satu pendaftaran pada satu tahap seleksi, beserta nilainya.
 */
class PpdbSelectionParticipant extends BaseModel
{
    public const STATUS_INVITED = 'invited';
    public const STATUS_ATTENDED = 'attended';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_SCORED = 'scored';

    public const STATUSES = [
        self::STATUS_INVITED, self::STATUS_ATTENDED, self::STATUS_ABSENT, self::STATUS_SCORED,
    ];

    protected $casts = ['score' => 'decimal:2', 'attended_at' => 'datetime'];

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PpdbSelectionStage::class, 'stage_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(PpdbRegistration::class, 'registration_id');
    }

    public function scoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scored_by');
    }
}