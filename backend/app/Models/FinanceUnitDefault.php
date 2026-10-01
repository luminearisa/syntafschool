<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Unit bawaan per modul asal jurnal.
 *
 * Dipakai HANYA bila pemanggil tidak menentukan unit — satu baris per modul,
 * sehingga tidak pernah ada dua jawaban atas pertanyaan "jurnal modul ini masuk
 * unit mana?".
 *
 * Tabel: `finance_unit_defaults`
 */
class FinanceUnitDefault extends BaseModel
{
    protected $table = 'finance_unit_defaults';

    protected $fillable = ['sumber_modul', 'business_unit_code'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(FinanceBusinessUnit::class, 'business_unit_code', 'code');
    }
}
