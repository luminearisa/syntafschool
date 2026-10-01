<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Aset tetap beserta penyusutannya.
 *
 * `penyusutan_bulanan` dihitung dari (harga perolehan − nilai residu) ÷ umur,
 * bukan diketik manual: angka yang diketik manual akan berbeda-beda antar
 * pencatat, dan selisihnya baru terlihat saat aset sudah habis umurnya.
 *
 * Tabel: `finance_assets`
 */
class FinanceAsset extends BaseModel
{
    use SoftDeletes;

    protected $table = 'finance_assets';

    protected $fillable = [
        'kode_aset', 'nama', 'kategori', 'tanggal_perolehan', 'harga_perolehan', 'nilai_residu',
        'umur_bulan', 'metode', 'akun_aset_id', 'akun_akumulasi_id', 'akun_beban_depresiasi_id',
        'kode_unit', 'kode_dana', 'penyusutan_bulanan', 'akumulasi_penyusutan', 'nilai_buku',
        'status', 'tanggal_pelepasan', 'hasil_pelepasan', 'transaction_id', 'keterangan', 'created_by',
    ];

    protected $casts = [
        'tanggal_perolehan' => 'date',
        'tanggal_pelepasan' => 'date',
        'harga_perolehan' => 'decimal:2',
        'nilai_residu' => 'decimal:2',
        'penyusutan_bulanan' => 'decimal:2',
        'akumulasi_penyusutan' => 'decimal:2',
        'nilai_buku' => 'decimal:2',
        'hasil_pelepasan' => 'decimal:2',
        'umur_bulan' => 'integer',
    ];

    public function akunAset(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'akun_aset_id');
    }

    public function akunAkumulasi(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'akun_akumulasi_id');
    }

    public function akunBebanDepresiasi(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'akun_beban_depresiasi_id');
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(FinanceAssetDepreciation::class, 'asset_id');
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('status', 'aktif');
    }

    /**
     * Penyusutan satu bulan menurut metode aset.
     *
     * Garis lurus: (harga − residu) ÷ umur. Saldo menurun: 2 ÷ umur × nilai buku
     * berjalan — dan TIDAK pernah melewati nilai residu, karena aset yang
     * tersusutkan melebihi harga perolehannya adalah temuan audit, bukan hasil
     * pembulatan.
     */
    public function penyusutanBulanan(): string
    {
        if ($this->umur_bulan <= 0) {
            return Money::of(0);
        }

        if ($this->metode === 'saldo_menurun') {
            $nilaiBuku = $this->nilai_buku !== null ? (string) $this->nilai_buku : (string) $this->harga_perolehan;
            $tarif = Money::div(2, (string) $this->umur_bulan, 6);
            $nilai = Money::mul($nilaiBuku, $tarif);

            $tersedia = Money::sub($nilaiBuku, $this->nilai_residu);

            return Money::lt($nilai, $tersedia) ? $nilai : Money::abs($tersedia);
        }

        $dapatDisusut = Money::sub($this->harga_perolehan, $this->nilai_residu);

        return Money::div($dapatDisusut, (string) $this->umur_bulan);
    }

    /** Sisa nilai yang masih dapat disusutkan pada bulan ini. */
    public function sisaDapatDisusut(): string
    {
        $sisa = Money::sub(
            Money::sub($this->harga_perolehan, $this->nilai_residu),
            $this->akumulasi_penyusutan,
        );

        return Money::isNegative($sisa) ? Money::of(0) : $sisa;
    }
}
