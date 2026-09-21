<?php

namespace App\Models;

use App\Models\Concerns\LogsPpdbActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Program yang dibuka pada sebuah periode PPDB (mis. SMP Boarding, SMP Tahfidz).
 *
 * Menautkan (opsional) ke master `programs` yang sudah ada; bila penawaran PPDB
 * berbeda dari master akademik, cukup isi nama sendiri.
 */
class PpdbProgram extends BaseModel
{
    use LogsPpdbActivity;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $casts = ['quota' => 'integer', 'sort_order' => 'integer'];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PpdbPeriod::class, 'period_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(PpdbRegistration::class, 'program_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function activityEventPrefix(): string
    {
        return 'PPDB_PROGRAM';
    }
}