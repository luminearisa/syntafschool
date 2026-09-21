<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Target hafalan per peserta.
 *
 * Setiap peserta boleh punya target berbeda — kecepatan hafalan bervariasi,
 * jangan memaksa satu halaqah memiliki target yang sama.
 */
class TahfizIndividualTarget extends BaseModel
{
    protected $casts = [
        'value' => 'decimal:2',
        'is_active' => 'boolean',
        'effective_from' => 'date:Y-m-d',
        'effective_until' => 'date:Y-m-d',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(TahfizEnrollment::class, 'enrollment_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TahfizParticipant::class, 'participant_id');
    }

    public function surah(): BelongsTo
    {
        return $this->belongsTo(QuranSurah::class, 'surah_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
