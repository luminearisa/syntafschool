<?php

namespace App\Models;

use App\Models\Concerns\LogsPpdbActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Biaya pendaftaran/seleksi (scoped: periode / program / gelombang).
 *
 * Gelombang boleh punya biaya berbeda — inilah sebabnya scope gelombang ada.
 */
class PpdbRegistrationFee extends BaseModel
{
    use LogsPpdbActivity;

    public const TYPE_REGISTRATION = 'registration';
    public const TYPE_SELECTION = 'selection';
    public const TYPE_TEST = 'test';
    public const TYPE_PSYCHOLOGY = 'psychology';
    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_REGISTRATION, self::TYPE_SELECTION, self::TYPE_TEST,
        self::TYPE_PSYCHOLOGY, self::TYPE_OTHER,
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'required' => 'boolean',
        'active' => 'boolean',
        'payment_deadline' => 'date:Y-m-d',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PpdbPeriod::class, 'period_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(PpdbProgram::class, 'program_id');
    }

    public function wave(): BelongsTo
    {
        return $this->belongsTo(PpdbWave::class, 'wave_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function activityEventPrefix(): string
    {
        return 'PPDB_FEE';
    }
}