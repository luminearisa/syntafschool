<?php

namespace App\Modules\Hrd\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rincian perubahan satu transaksi (§33, §34).
 *
 * Inilah yang membuat layar persetujuan bermakna: approver melihat
 * "Departemen: Finance → Academic", bukan sekadar tombol setuju.
 */
class HrTransactionItem extends HrdModel
{
    protected $casts = [
        'before_value' => 'array',
        'after_value' => 'array',
        'sort_order' => 'integer',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(HrTransaction::class, 'transaction_id');
    }

    /** Nilai yang ditampilkan: skalar apa adanya, array dijadikan JSON ringkas. */
    public function describe(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—';
    }
}
