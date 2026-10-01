<?php

namespace App\Services\Finance\Accounting;

use App\Models\FinanceAccount;
use App\Models\FinanceAsset;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\Finance\FinancialTransactionEngine;
use App\Support\Accounting\CoaHierarchy;
use App\Support\Accounting\SumberModul;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aset tetap: pendaftaran, penyusutan (lihat `DepreciationService`), dan pelepasan.
 *
 * Pelepasan aset bukan sekadar mengubah status. Ia satu jurnal yang harus
 * menjelaskan seluruh perjalanan aset itu: uang yang diterima, akumulasi
 * penyusutan yang dibuang, harga perolehan yang dikeluarkan dari neraca, dan
 * selisihnya sebagai laba atau rugi. Tanpa jurnal itu, "aset dilepas" hanya
 * menjadi catatan yang tidak dapat ditelusuri bukti pembukuannya.
 */
class AssetService
{
    public function __construct(private readonly FinancialTransactionEngine $ledger) {}

    /** @return \Illuminate\Database\Eloquent\Collection<int, FinanceAsset> */
    public function all(array $filters = [])
    {
        return FinanceAsset::query()
            ->with(['akunAset:id,code,name', 'akunAkumulasi:id,code,name', 'akunBebanDepresiasi:id,code,name'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['kode_unit'] ?? null, fn ($q, $unit) => $q->where('kode_unit', $unit))
            ->when($filters['search'] ?? null, fn ($q, $cari) => $q->where(function ($sub) use ($cari): void {
                $sub->where('nama', 'like', "%{$cari}%")->orWhere('kode_aset', 'like', "%{$cari}%");
            }))
            ->orderBy('kode_aset')
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function save(array $data, ?int $id = null, ?User $actor = null): FinanceAsset
    {
        $asset = $id === null ? new FinanceAsset() : FinanceAsset::query()->findOrFail($id);

        $kode = (string) ($data['kode_aset'] ?? $asset->kode_aset ?? '');

        if ($kode === '') {
            throw ValidationException::withMessages(['kode_aset' => 'Kode aset wajib diisi.']);
        }

        $asset->fill([
            'kode_aset' => $kode,
            'nama' => $data['nama'] ?? $asset->nama,
            'kategori' => $data['kategori'] ?? $asset->kategori,
            'tanggal_perolehan' => $data['tanggal_perolehan'] ?? $asset->tanggal_perolehan,
            'harga_perolehan' => $data['harga_perolehan'] ?? $asset->harga_perolehan,
            'nilai_residu' => $data['nilai_residu'] ?? $asset->nilai_residu ?? 0,
            'umur_bulan' => $data['umur_bulan'] ?? $asset->umur_bulan,
            'metode' => $data['metode'] ?? $asset->metode ?? 'garis_lurus',
            'akun_aset_id' => $data['akun_aset_id'] ?? $asset->akun_aset_id,
            'akun_akumulasi_id' => $data['akun_akumulasi_id'] ?? $asset->akun_akumulasi_id,
            'akun_beban_depresiasi_id' => $data['akun_beban_depresiasi_id'] ?? $asset->akun_beban_depresiasi_id,
            'kode_unit' => $data['kode_unit'] ?? $asset->kode_unit,
            'kode_dana' => $data['kode_dana'] ?? $asset->kode_dana,
            'keterangan' => $data['keterangan'] ?? $asset->keterangan,
            'created_by' => $asset->created_by ?? $actor?->id,
        ]);

        $this->assertAkun($asset);

        if ($asset->metode === 'garis_lurus') {
            $asset->penyusutan_bulanan = $asset->penyusutanBulanan();
        }

        // Nilai buku hanya dihitung ulang saat dibuat: sesudah itu ia bergerak
        // lewat penyusutan & pelepasan, bukan lewat suntingan formulir.
        if (! $asset->exists) {
            $asset->nilai_buku = Money::sub($asset->harga_perolehan, $asset->akumulasi_penyusutan ?? 0);
        }

        $asset->save();

        return $asset->refresh();
    }

    /**
     * Pelepasan aset: satu jurnal yang menutup seluruh perjalanan aset.
     *
     * @param  array<string, mixed>  $data  hasil_pelepasan, tanggal_pelepasan, akun_penerimaan_id, akun_laba_id, akun_rugi_id, keterangan
     */
    public function dispose(FinanceAsset $asset, array $data, User $actor): FinancialTransaction
    {
        if ($asset->status !== 'aktif') {
            throw ValidationException::withMessages([
                'status' => "Aset {$asset->kode_aset} sudah berstatus {$asset->status}; tidak dapat dilepas dua kali.",
            ]);
        }

        $harga = Money::of($asset->harga_perolehan);
        $akumulasi = Money::of($asset->akumulasi_penyusutan);
        $hasil = Money::of($data['hasil_pelepasan'] ?? 0);
        $nilaiBuku = Money::sub($harga, $akumulasi);
        $selisih = Money::sub($hasil, $nilaiBuku);

        $lines = [];

        if (Money::gtZero($hasil)) {
            $akunPenerimaan = (int) ($data['akun_penerimaan_id'] ?? 0);

            if ($akunPenerimaan <= 0) {
                throw ValidationException::withMessages([
                    'akun_penerimaan_id' => 'Ada hasil pelepasan yang diterima, tetapi akun penerimaannya belum dipilih.',
                ]);
            }

            $lines[] = ['account_id' => $akunPenerimaan, 'debit' => $hasil, 'description' => "Hasil pelepasan {$asset->kode_aset}"];
        }

        if (Money::gtZero($akumulasi)) {
            $lines[] = ['account_id' => $asset->akun_akumulasi_id, 'debit' => $akumulasi, 'description' => "Pembuangan akumulasi penyusutan {$asset->kode_aset}"];
        }

        if (Money::isNegative($selisih)) {
            $akunRugi = (int) ($data['akun_rugi_id'] ?? 0);

            if ($akunRugi <= 0) {
                throw ValidationException::withMessages([
                    'akun_rugi_id' => 'Pelepasan ini menimbulkan kerugian '.Money::format(Money::abs($selisih)).', tetapi akun kerugian pelepasan belum dipilih.',
                ]);
            }

            $lines[] = ['account_id' => $akunRugi, 'debit' => Money::abs($selisih), 'description' => "Kerugian pelepasan {$asset->kode_aset}"];
        }

        $lines[] = ['account_id' => $asset->akun_aset_id, 'credit' => $harga, 'description' => "Pengeluaran aset {$asset->kode_aset}"];

        if (Money::gtZero($selisih)) {
            $akunLaba = (int) ($data['akun_laba_id'] ?? 0);

            if ($akunLaba <= 0) {
                throw ValidationException::withMessages([
                    'akun_laba_id' => 'Pelepasan ini menghasilkan laba '.Money::format($selisih).', tetapi akun laba pelepasan belum dipilih.',
                ]);
            }

            $lines[] = ['account_id' => $akunLaba, 'credit' => $selisih, 'description' => "Laba pelepasan {$asset->kode_aset}"];
        }

        return DB::transaction(function () use ($asset, $data, $actor, $lines): FinancialTransaction {
            $transaction = $this->ledger->post(
                type: FinancialTransaction::TYPE_ADJUSTMENT,
                description: 'Pelepasan aset '.$asset->kode_aset.' — '.$asset->nama,
                lines: $lines,
                context: [
                    'date' => $data['tanggal_pelepasan'] ?? now()->toDateString(),
                    'sumber_modul' => SumberModul::PELEPASAN_ASET,
                    'reference_type' => FinanceAsset::class,
                    'reference_id' => $asset->id,
                    'created_by' => $actor->id,
                    'kode_unit' => $asset->kode_unit,
                    'kode_dana' => $asset->kode_dana,
                ],
            );

            $asset->forceFill([
                'status' => 'dilepas',
                'tanggal_pelepasan' => $data['tanggal_pelepasan'] ?? now()->toDateString(),
                'hasil_pelepasan' => $data['hasil_pelepasan'] ?? 0,
                'transaction_id' => $transaction->id,
                'nilai_buku' => Money::of(0),
            ])->save();

            return $transaction;
        });
    }

    protected function assertAkun(FinanceAsset $asset): void
    {
        $akun = FinanceAccount::query()
            ->whereIn('id', array_filter([$asset->akun_aset_id, $asset->akun_akumulasi_id, $asset->akun_beban_depresiasi_id]))
            ->get()
            ->keyBy('id');

        foreach ([
            'akun_aset_id' => $asset->akun_aset_id,
            'akun_akumulasi_id' => $asset->akun_akumulasi_id,
            'akun_beban_depresiasi_id' => $asset->akun_beban_depresiasi_id,
        ] as $field => $id) {
            $a = $akun->get((int) $id);

            if ($a === null) {
                throw ValidationException::withMessages([$field => 'Akun wajib dipilih dan harus ada.']);
            }

            if (! $a->isLeaf()) {
                throw ValidationException::withMessages([$field => "Akun {$a->code} adalah akun induk; pilih akun rincian."]);
            }
        }

        // Akun beban penyusutan harus benar-benar akun beban: menyusutkan aset ke
        // akun neraca akan membuat beban penyusutan tidak pernah muncul di laba rugi.
        $akunBeban = $akun->get((int) $asset->akun_beban_depresiasi_id);

        if (CoaHierarchy::dariAkun($akunBeban) !== CoaHierarchy::BEBAN) {
            throw ValidationException::withMessages([
                'akun_beban_depresiasi_id' => "Akun {$akunBeban?->code} bukan akun beban (kelompok 5), sehingga penyusutan tidak akan terlihat sebagai beban.",
            ]);
        }

        if ($asset->umur_bulan !== null && (int) $asset->umur_bulan <= 0) {
            throw ValidationException::withMessages(['umur_bulan' => 'Umur ekonomis harus lebih dari nol bulan.']);
        }

        if (Money::gt($asset->nilai_residu ?? 0, $asset->harga_perolehan ?? 0)) {
            throw ValidationException::withMessages(['nilai_residu' => 'Nilai residu tidak boleh melebihi harga perolehan.']);
        }
    }
}
