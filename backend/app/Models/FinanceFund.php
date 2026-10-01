<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dana (fund accounting).
 *
 * Dana bebas boleh dipakai untuk apa saja; dana terikat hanya untuk belanja yang
 * masuk daftar peruntukannya; wakaf/zakat/infaq/sedekah mengikuti aturan
 * syariahnya sendiri. Penjaga peruntukan hanya membatasi baris BEBAN — kasnya
 * menumpang rekening yang sama dengan dana lain, dan pendapatannya justru
 * penerimaan dana itu.
 *
 * Tabel: `finance_funds`
 */
class FinanceFund extends BaseModel
{
    protected $table = 'finance_funds';

    protected $fillable = ['kode_dana', 'nama_dana', 'jenis', 'peruntukan', 'status', 'keterangan'];

    public function accounts(): HasMany
    {
        return $this->hasMany(FinanceFundAccount::class, 'kode_dana', 'kode_dana');
    }

    public function contraAccounts(): HasMany
    {
        return $this->hasMany(FinanceFundContraAccount::class, 'kode_dana', 'kode_dana');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'aktif');
    }

    public function isTerikat(): bool
    {
        return $this->jenis !== 'bebas';
    }

    public function labelJenis(): string
    {
        return match ($this->jenis) {
            'bebas' => 'Dana Bebas',
            'terikat' => 'Dana Terikat',
            'wakaf' => 'Dana Wakaf',
            'zakat' => 'Dana Zakat',
            'infaq' => 'Dana Infaq',
            'sedekah' => 'Dana Sedekah',
            'temporer' => 'Dana Temporer',
            default => 'Dana',
        };
    }
}
