<?php

namespace App\Services\Finance\Accounting;

use App\Models\FinanceAsset;
use App\Models\FinanceAssetDepreciation;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\Finance\FinancialTransactionEngine;
use App\Support\Accounting\SumberModul;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penyusutan aset tetap per periode.
 *
 * Dua penjaga yang membuatnya aman dijalankan berkali-kali:
 *   1. `unique(asset_id, periode)` di basis data — bukan pemeriksaan di layar;
 *   2. sisa yang dapat disusutkan dihitung ulang dariakumulasi berjalan, sehingga
 *      aset tidak pernah tersusutkan melebihi harga perolehannya.
 *
 * `preview()` disediakan karena pengguna berhak melihat akibatnya lebih dulu:
 * menjalankan penyusutan untuk 400 aset tanpa pratinjau adalah cara cepat
 * membuat jurnal yang tidak diinginkan.
 */
class DepreciationService
{
    public function __construct(private readonly FinancialTransactionEngine $ledger) {}

    /**
     * Pratinjau penyusutan sebuah periode (tanpa menulis apa pun).
     *
     * @return array{periode: string, tanggal: string, rows: list<array<string, mixed>>, total: string}
     */
    public function preview(string $periode): array
    {
        [$awal, $akhir] = $this->batasPeriode($periode);

        $rows = [];
        $total = '0.00';

        // `->get()` wajib: Builder Eloquent bukan koleksi, dan melakukan foreach
        // langsung di atasnya menyerahkan isi properti internal (array) — bukan
        // model aset — ke pemanggil berikutnya.
        foreach ($this->asetUntukPeriode($awal)->get() as $asset) {
            if ($this->sudahDisusutkan($asset, $periode)) {
                continue;
            }

            $nilai = $this->nilaiPenyusutan($asset);

            if (Money::isZero($nilai)) {
                continue;
            }

            $total = Money::add($total, $nilai);

            $rows[] = [
                'asset_id' => $asset->id,
                'kode_aset' => $asset->kode_aset,
                'nama' => $asset->nama,
                'metode' => $asset->metode,
                'nilai_buku' => (string) $asset->nilai_buku,
                'nilai' => $nilai,
            ];
        }

        return ['periode' => $periode, 'tanggal' => $akhir->toDateString(), 'rows' => $rows, 'total' => $total];
    }

    /**
     * Menjalankan penyusutan sebuah periode.
     *
     * @return array{periode: string, diposting: int, dilewati: int, total: string, rows: list<array<string, mixed>>}
     */
    public function run(string $periode, User $actor, ?int $assetId = null): array
    {
        [$awal, $akhir] = $this->batasPeriode($periode);

        $query = $this->asetUntukPeriode($awal);

        if ($assetId !== null) {
            $query->whereKey($assetId);
        }

        $diposting = 0;
        $dilewati = 0;
        $total = '0.00';
        $rows = [];

        foreach ($query->get() as $asset) {
            if ($this->sudahDisusutkan($asset, $periode)) {
                $dilewati++;

                continue;
            }

            $nilai = $this->nilaiPenyusutan($asset);

            if (Money::isZero($nilai)) {
                $dilewati++;

                continue;
            }

            $depresiasi = DB::transaction(function () use ($asset, $periode, $akhir, $nilai, $actor): FinanceAssetDepreciation {
                $transaction = $this->ledger->post(
                    type: FinancialTransaction::TYPE_ADJUSTMENT,
                    description: "Penyusutan {$periode} — {$asset->kode_aset} {$asset->nama}",
                    lines: [
                        ['account_id' => $asset->akun_beban_depresiasi_id, 'debit' => $nilai, 'description' => "Beban penyusutan {$asset->kode_aset}"],
                        ['account_id' => $asset->akun_akumulasi_id, 'credit' => $nilai, 'description' => "Akumulasi penyusutan {$asset->kode_aset}"],
                    ],
                    context: [
                        'date' => $akhir,
                        'sumber_modul' => SumberModul::DEPRESIASI,
                        'reference_type' => FinanceAsset::class,
                        'reference_id' => $asset->id,
                        'created_by' => $actor->id,
                        // Unit & dana dibawa dari asetnya: beban penyusutan milik unit
                        // yang memakai aset itu, bukan unit yang membayar kasnya.
                        'kode_unit' => $asset->kode_unit,
                        'kode_dana' => $asset->kode_dana,
                    ],
                );

                $akumulasiBaru = Money::add($asset->akumulasi_penyusutan, $nilai);

                $asset->forceFill([
                    'akumulasi_penyusutan' => $akumulasiBaru,
                    'nilai_buku' => Money::sub($asset->harga_perolehan, $akumulasiBaru),
                    // Saldo menurun: angka bulanannya berubah, jadi disimpan ulang
                    // setelah setiap kali penyusutan diposting.
                    'penyusutan_bulanan' => $asset->metode === 'saldo_menurun'
                        ? $asset->penyusutanBulanan()
                        : $asset->penyusutan_bulanan,
                ])->save();

                return FinanceAssetDepreciation::query()->create([
                    'asset_id' => $asset->id,
                    'periode' => $periode,
                    'tanggal' => $akhir->toDateString(),
                    'nilai' => $nilai,
                    'transaction_id' => $transaction->id,
                    'posted_at' => now(),
                    'created_by' => $actor->id,
                ]);
            });

            $diposting++;
            $total = Money::add($total, $nilai);
            $rows[] = [
                'asset_id' => $asset->id,
                'kode_aset' => $asset->kode_aset,
                'nilai' => (string) $depresiasi->nilai,
                'transaction_id' => $depresiasi->transaction_id,
            ];
        }

        return ['periode' => $periode, 'diposting' => $diposting, 'dilewati' => $dilewati, 'total' => $total, 'rows' => $rows];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    protected function batasPeriode(string $periode): array
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $periode)) {
            throw ValidationException::withMessages([
                'periode' => "Periode harus berformat YYYY-MM (contoh 2026-07); diterima: '{$periode}'.",
            ]);
        }

        $awal = Carbon::createFromFormat('Y-m-d', $periode.'-01')->startOfMonth();
        $akhir = $awal->copy()->endOfMonth();

        // Tanggal jurnal tidak boleh di masa depan: jurnal bertanggal besok akan
        // ditolak oleh periode yang belum dibuka, dan galatnya membingungkan.
        if ($akhir->isAfter(now()->endOfDay())) {
            $akhir = now()->endOfDay();
        }

        return [$awal, $akhir];
    }

    protected function asetUntukPeriode(Carbon $awal)
    {
        return FinanceAsset::query()
            ->aktif()
            ->whereDate('tanggal_perolehan', '<=', $awal->toDateString())
            ->orderBy('kode_aset');
    }

    protected function sudahDisusutkan(FinanceAsset $asset, string $periode): bool
    {
        return FinanceAssetDepreciation::query()
            ->where('asset_id', $asset->id)
            ->where('periode', $periode)
            ->exists();
    }

    /** Penyusutan bulan ini: tidak melebihi sisa yang masih dapat disusutkan. */
    protected function nilaiPenyusutan(FinanceAsset $asset): string
    {
        $bulanan = Money::of($asset->penyusutanBulanan());
        $sisa = $asset->sisaDapatDisusut();

        return Money::lt($bulanan, $sisa) ? $bulanan : $sisa;
    }
}
