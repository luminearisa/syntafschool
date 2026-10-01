<?php

namespace App\Services\Finance\Accounting;

use App\Models\FinanceAccount;
use App\Models\FinancialTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\Finance\FinancialTransactionEngine;
use App\Support\Accounting\CoaHierarchy;
use App\Support\Accounting\SumberModul;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Persediaan yang bermuara ke buku besar.
 *
 * Sebelum ini kartu persediaan dan buku besar hidup terpisah: stok berubah di
 * satu layar, nilainya di layar lain, dan selisihnya baru ketahuan saat opname.
 * Di sini satu mutasi menghasilkan **satu** jurnal sekaligus **satu** pergerakan
 * stok, dalam satu transaksi basis data — sehingga tidak mungkin hanya salah
 * satunya yang tercatat.
 *
 * Barang & jumlahnya melekat pada baris jurnal (`kode_persediaan`, `kuantiti`),
 * jadi laporan per barang dapat disusun langsung dari buku besar.
 */
class InventoryLedgerService
{
    public function __construct(private readonly FinancialTransactionEngine $ledger) {}

    /**
     * Barang masuk: menambah stok sekaligus mencatat nilai persediaannya.
     *
     * @param  array<string, mixed>  $data  kuantiti, harga_satuan, akun_persediaan_id, akun_sumber_id, tanggal?, reference?, keterangan?
     * @return array{transaction: FinancialTransaction, movement: InventoryMovement, nilai: string}
     */
    public function masuk(InventoryItem $item, array $data, User $actor): array
    {
        return $this->catat($item, 'in', $data, $actor);
    }

    /**
     * Barang keluar: mengurangi stok sekaligus membebankan nilainya.
     *
     * @param  array<string, mixed>  $data  kuantiti, harga_satuan?, akun_persediaan_id, akun_beban_id, tanggal?, reference?, keterangan?
     * @return array{transaction: FinancialTransaction, movement: InventoryMovement, nilai: string}
     */
    public function keluar(InventoryItem $item, array $data, User $actor): array
    {
        return $this->catat($item, 'out', $data, $actor);
    }

    /** @param array<string, mixed> $data */
    protected function catat(InventoryItem $item, string $direction, array $data, User $actor): array
    {
        $kuantiti = Money::quantity($data['kuantiti'] ?? 0);

        if (! Money::gtZero($kuantiti, Money::SCALE_QUANTITY)) {
            throw ValidationException::withMessages(['kuantiti' => 'Jumlah barang harus lebih besar dari nol.']);
        }

        $hargaSatuan = Money::of($data['harga_satuan'] ?? $item->unit_cost ?? 0);

        if (! Money::gtZero($hargaSatuan)) {
            throw ValidationException::withMessages([
                'harga_satuan' => 'Harga satuan wajib diisi (dan lebih dari nol) agar nilai persediaan dapat dihitung; barang yang dinilai nol akan membuat beban hilang dari laporan.',
            ]);
        }

        $nilai = Money::mul($kuantiti, $hargaSatuan, Money::SCALE_QUANTITY);
        $nilai = Money::of($nilai);

        if (! Money::gtZero($nilai)) {
            throw ValidationException::withMessages(['kuantiti' => 'Nilai barang membulat menjadi nol; periksa jumlah dan harga satuannya.']);
        }

        $akunPersediaan = $this->akunDaun((int) ($data['akun_persediaan_id'] ?? 0), 'akun_persediaan_id');

        if (CoaHierarchy::dariAkun($akunPersediaan) !== CoaHierarchy::ASET) {
            throw ValidationException::withMessages([
                'akun_persediaan_id' => "Akun persediaan harus akun aset (kelompok 1); akun {$akunPersediaan->code} bukan kelompok 1.",
            ]);
        }

        $akunLawan = $direction === 'in'
            ? $this->akunDaun((int) ($data['akun_sumber_id'] ?? 0), 'akun_sumber_id')
            : $this->akunDaun((int) ($data['akun_beban_id'] ?? 0), 'akun_beban_id');

        if ($direction === 'out' && CoaHierarchy::dariAkun($akunLawan) !== CoaHierarchy::BEBAN) {
            throw ValidationException::withMessages([
                'akun_beban_id' => "Pemakaian barang harus dibebankan ke akun beban (kelompok 5); akun {$akunLawan->code} bukan kelompok 5.",
            ]);
        }

        return DB::transaction(function () use ($item, $direction, $data, $actor, $kuantiti, $nilai, $akunPersediaan, $akunLawan): array {
            // Stok dibaca ulang dengan kunci baris: dua permintaan bersamaan tidak
            // boleh menghasilkan stok negatif hanya karena sama-sama membaca angka lama.
            $fresh = InventoryItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $sebelum = Money::quantity($fresh->quantity);
            $sesudah = $direction === 'in' ? Money::add($sebelum, $kuantiti, Money::SCALE_QUANTITY) : Money::sub($sebelum, $kuantiti, Money::SCALE_QUANTITY);

            if ($direction === 'out' && Money::isNegative($sesudah, Money::SCALE_QUANTITY)) {
                throw ValidationException::withMessages([
                    'kuantiti' => "Stok {$fresh->sku} hanya {$sebelum} {$fresh->unit?->name}; tidak dapat dikeluarkan {$kuantiti}.",
                ]);
            }

            $dimensi = ['kode_persediaan' => $fresh->sku, 'kuantiti' => $kuantiti];

            $lines = $direction === 'in'
                ? [
                    array_merge(['account_id' => $akunPersediaan->id, 'debit' => $nilai, 'description' => "Persediaan masuk {$fresh->sku}"], $dimensi),
                    array_merge(['account_id' => $akunLawan->id, 'credit' => $nilai, 'description' => $data['keterangan'] ?? "Pembelian persediaan {$fresh->sku}"], $dimensi),
                ]
                : [
                    array_merge(['account_id' => $akunLawan->id, 'debit' => $nilai, 'description' => $data['keterangan'] ?? "Pemakaian persediaan {$fresh->sku}"], $dimensi),
                    array_merge(['account_id' => $akunPersediaan->id, 'credit' => $nilai, 'description' => "Persediaan keluar {$fresh->sku}"], $dimensi),
                ];

            $transaction = $this->ledger->post(
                type: $direction === 'in' ? FinancialTransaction::TYPE_EXPENSE : FinancialTransaction::TYPE_EXPENSE,
                description: ($direction === 'in' ? 'Persediaan masuk ' : 'Persediaan keluar ').$fresh->sku.' — '.$fresh->name,
                lines: $lines,
                context: [
                    'date' => $data['tanggal'] ?? now()->toDateString(),
                    'sumber_modul' => SumberModul::PERSEDIAAN,
                    'reference_type' => InventoryItem::class,
                    'reference_id' => $fresh->id,
                    'created_by' => $actor->id,
                    'kode_unit' => $data['kode_unit'] ?? null,
                    'kode_dana' => $data['kode_dana'] ?? null,
                ],
            );

            $movement = InventoryMovement::query()->create([
                'inventory_item_id' => $fresh->id,
                'movement_type' => $direction,
                'quantity' => $kuantiti,
                'quantity_before' => $sebelum,
                'quantity_after' => $sesudah,
                'reason' => $data['keterangan'] ?? ($direction === 'in' ? 'Barang masuk (jurnal)' : 'Barang keluar (jurnal)'),
                'reference' => $data['reference'] ?? $transaction->transaction_number,
                'created_by' => $actor->id,
            ]);

            $fresh->forceFill([
                'quantity' => $sesudah,
                // Harga pokok terakhir yang benar-benar dibayar dipakai sebagai
                // taksiran nilai untuk pemakaian berikutnya.
                'unit_cost' => $direction === 'in' ? Money::of($data['harga_satuan'] ?? $fresh->unit_cost) : $fresh->unit_cost,
            ])->save();

            return ['transaction' => $transaction, 'movement' => $movement, 'nilai' => $nilai];
        });
    }

    protected function akunDaun(int $accountId, string $field): FinanceAccount
    {
        if ($accountId <= 0) {
            throw ValidationException::withMessages([$field => 'Akun wajib dipilih.']);
        }

        $akun = FinanceAccount::query()->find($accountId);

        if ($akun === null) {
            throw ValidationException::withMessages([$field => 'Akun tidak ditemukan.']);
        }

        if (! $akun->isLeaf()) {
            throw ValidationException::withMessages([$field => "Akun {$akun->code} adalah akun induk; pilih akun rincian."]);
        }

        if (! $akun->is_active) {
            throw ValidationException::withMessages([$field => "Akun {$akun->code} tidak aktif."]);
        }

        return $akun;
    }
}
