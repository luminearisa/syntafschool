<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permohonan membuka kembali periode yang sudah ditutup.
 *
 * Tutup buku mengikat: periode tertutup menolak seluruh jurnal tanpa toleransi
 * hari. Membukanya kembali bukan tindakan satu orang — permohonan diajukan
 * beserta alasannya, lalu diputuskan orang lain, dan jejaknya tersimpan.
 *
 * Tabel: `finance_period_reopen_requests`
 */
class FinancePeriodReopenRequest extends BaseModel
{
    protected $table = 'finance_period_reopen_requests';

    protected $casts = ['decided_at' => 'datetime'];

    public function period(): BelongsTo
    {
        return $this->belongsTo(FinancePeriod::class, 'period_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }
}
