<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris penyusutan aset untuk satu periode.
 *
 * `unique(asset_id, periode)` adalah penjaga idempotensi: menjalankan penyusutan
 * dua kali pada bulan yang sama tidak boleh menyusutkan aset dua kali. Bukan
 * lewat pemeriksaan di layar, melainkan lewat kunci di basis data.
 *
 * Tabel: `finance_asset_depreciations`
 */
class FinanceAssetDepreciation extends BaseModel
{
    protected $table = 'finance_asset_depreciations';

    protected $fillable = ['asset_id', 'periode', 'tanggal', 'nilai', 'transaction_id', 'posted_at', 'created_by'];

    protected $casts = [
        'tanggal' => 'date',
        'nilai' => 'decimal:2',
        'posted_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FinanceAsset::class, 'asset_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class, 'transaction_id');
    }
}
