<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permohonan membatalkan (void) jurnal yang sudah diposting.
 *
 * Jurnal yang sudah diposting tidak pernah dihapus maupun disunting; ia
 * dibatalkan lewat jurnal pembalik. Permohonan ini memisahkan orang yang meminta
 * dari orang yang menyetujui, sehingga pembatalan jurnal bernilai besar tidak
 * dapat dilakukan seorang diri tanpa jejak.
 *
 * Tabel: `finance_journal_void_requests`
 */
class FinanceJournalVoidRequest extends BaseModel
{
    protected $table = 'finance_journal_void_requests';

    protected $casts = ['decided_at' => 'datetime'];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'transaction_id');
    }

    public function reversalTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'reversal_transaction_id');
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
