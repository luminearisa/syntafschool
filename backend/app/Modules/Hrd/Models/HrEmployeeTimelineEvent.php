<?php

namespace App\Modules\Hrd\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linimasa pegawai (§24).
 *
 * Baris di sini **diturunkan dari peristiwa domain**, bukan diketik manual:
 * "01 Jul 2026 · Dipromosikan menjadi Koordinator" muncul karena transaksi
 * promosi disetujui dan diterapkan. `dedupe_key` mencegah satu peristiwa tercatat
 * dua kali ketika listener dijalankan ulang oleh antrean.
 */
class HrEmployeeTimelineEvent extends HrdModel
{
    protected $casts = [
        'occurred_at' => 'datetime',
        'effective_date' => 'date:Y-m-d',
        'meta' => 'array',
    ];

    public const VISIBILITY = ['private', 'hr', 'manager', 'employee', 'public'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function scopeVisibleTo(Builder $query, string $level): Builder
    {
        $allowed = match ($level) {
            'employee' => ['employee', 'public'],
            'manager' => ['manager', 'employee', 'public'],
            'hr' => ['hr', 'manager', 'employee', 'public'],
            default => ['private', 'hr', 'manager', 'employee', 'public'],
        };

        return $query->whereIn('visibility', $allowed);
    }

    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderByDesc('occurred_at')->orderByDesc('id');
    }
}
