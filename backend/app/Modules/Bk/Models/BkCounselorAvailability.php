<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ketersediaan konselor per hari — dasar penjadwalan janji temu.
 *
 * Tabel: `bk_counselor_availability`
 */
class BkCounselorAvailability extends BkModel
{
    protected $table = 'bk_counselor_availability';

    protected $casts = [
        'is_active' => 'boolean',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function counselor(): BelongsTo
    {
        return $this->belongsTo(BkCounselor::class, 'counselor_id');
    }

    /** Slot yang berlaku pada tanggal tertentu. */
    public function scopeForDay(Builder $query, int $dayOfWeek): Builder
    {
        return $query->where('day_of_week', $dayOfWeek)->where('is_active', true);
    }
}
