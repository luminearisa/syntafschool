<?php

namespace App\Services\Finance\Accounting;

use App\Models\FinanceAccount;
use App\Models\FinanceFund;
use App\Models\FinanceFundContraAccount;
use App\Models\FinanceOpeningBalance;
use App\Support\Accounting\CoaHierarchy;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Laporan akuntansi standar: neraca saldo, laba rugi per unit, dan laporan dana.
 *
 * Tiga prinsip yang membentuk seluruh kelas ini:
 *
 *  1. **Angka dihitung dengan BCMath**, bukan float. Laporan yang jumlahnya
 *     berbeda satu sen dari buku besar akan menghabiskan waktu orang untuk
 *     mencari selisih yang tidak ada.
 *  2. **Jurnal yang dibatalkan tetap dihitung**, karena jurnal pembaliknya juga
 *     dihitung. Mengeluarkan jurnal void dari laporan justru membuat angka tidak
 *     pernah nol, dan sebabnya sulit dilihat.
 *  3. **Baris tanpa unit/dana tetap dilaporkan**, pada kelompok "TANPA UNIT" /
 *     "TANPA DANA". Menyembunyikannya akan membuat total per unit tampak rapi
 *     tetapi tidak pernah sama dengan total buku besar.
 */
class StandardReportService
{
    /**
     * Neraca saldo: saldo awal, mutasi periode, dan saldo akhir per akun.
     *
     * @return array{from: string, to: string, rows: list<array<string, mixed>>, totals: array<string, string>, balanced: bool}
     */
    public function trialBalance(?string $from = null, ?string $to = null): array
    {
        $awal = Carbon::parse($from ?? now()->startOfYear()->toDateString())->startOfDay();
        $akhir = Carbon::parse($to ?? now()->toDateString())->endOfDay();

        $accounts = FinanceAccount::query()->orderBy('code')->get(['id', 'code', 'name', 'type']);
        $opening = FinanceOpeningBalance::query()->posted()->get()->keyBy('account_id');

        $mutasi = $this->mutasiPerAkun($awal, $akhir);
        $sebelum = $this->mutasiPerAkun(null, $awal->copy()->subDay()->endOfDay());

        $rows = [];
        $totalDebit = '0.00';
        $totalCredit = '0.00';

        foreach ($accounts as $account) {
            $saldoAwal = $this->saldoAwal($opening->get($account->id), $sebelum[$account->id] ?? null);
            $debit = $mutasi[$account->id]['debit'] ?? '0.00';
            $kredit = $mutasi[$account->id]['credit'] ?? '0.00';

            // Akun tanpa saldo dan tanpa mutasi tidak ditampilkan: daftar akun
            // COA bisa ratusan baris, dan baris kosong menyembunyikan yang penting.
            if (Money::isZero($saldoAwal) && Money::isZero($debit) && Money::isZero($kredit)) {
                continue;
            }

            $saldoAkhir = Money::add($saldoAwal, Money::sub($debit, $kredit));

            if (! Money::isZero($saldoAkhir)) {
                if (Money::gtZero($saldoAkhir)) {
                    $totalDebit = Money::add($totalDebit, $saldoAkhir);
                } else {
                    $totalCredit = Money::add($totalCredit, Money::abs($saldoAkhir));
                }
            }

            $rows[] = [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'root' => CoaHierarchy::dariAkun($account),
                'root_label' => CoaHierarchy::label(CoaHierarchy::dariAkun($account)),
                'opening' => $saldoAwal,
                'debit' => $debit,
                'credit' => $kredit,
                'balance' => $saldoAkhir,
            ];
        }

        return [
            'from' => $awal->toDateString(),
            'to' => $akhir->toDateString(),
            'rows' => $rows,
            'totals' => ['debit' => $totalDebit, 'credit' => $totalCredit],
            'balanced' => Money::eq($totalDebit, $totalCredit),
        ];
    }

    /**
     * Laba rugi per unit bisnis.
     *
     * Hanya menyentuh akun pendapatan & beban: neraca sengaja tidak dipecah per
     * unit, karena rekening kas/bank menampung uang banyak unit sekaligus.
     *
     * @return array{from: string, to: string, units: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function profitLossByUnit(?string $from = null, ?string $to = null): array
    {
        $awal = Carbon::parse($from ?? now()->startOfYear()->toDateString())->startOfDay();
        $akhir = Carbon::parse($to ?? now()->toDateString())->endOfDay();

        $rows = DB::table('finance_transaction_lines as l')
            ->join('finance_transactions as t', 't.id', '=', 'l.finance_transaction_id')
            ->join('finance_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereBetween('t.transaction_date', [
                $awal->copy()->startOfDay()->toDateTimeString(),
                $akhir->copy()->endOfDay()->toDateTimeString(),
            ])
            ->get([
                'l.kode_unit',
                'l.debit',
                'l.credit',
                DB::raw("substr(a.code, 1, 1) as akar"),
            ]);

        $units = [];
        $totalPendapatan = '0.00';
        $totalBeban = '0.00';

        foreach ($rows as $row) {
            if (! in_array($row->akar, [CoaHierarchy::PENDAPATAN, CoaHierarchy::BEBAN], true)) {
                continue;
            }

            $unit = $row->kode_unit ?: 'TANPA UNIT';
            $units[$unit] ??= ['unit' => $unit, 'revenue' => '0.00', 'expense' => '0.00'];

            if ($row->akar === CoaHierarchy::PENDAPATAN) {
                $nilai = Money::sub($row->credit, $row->debit);
                $units[$unit]['revenue'] = Money::add($units[$unit]['revenue'], $nilai);
                $totalPendapatan = Money::add($totalPendapatan, $nilai);
            } else {
                $nilai = Money::sub($row->debit, $row->credit);
                $units[$unit]['expense'] = Money::add($units[$unit]['expense'], $nilai);
                $totalBeban = Money::add($totalBeban, $nilai);
            }
        }

        $result = [];
        foreach ($units as $unit) {
            $result[] = [
                'unit' => $unit['unit'],
                'revenue' => $unit['revenue'],
                'expense' => $unit['expense'],
                'result' => Money::sub($unit['revenue'], $unit['expense']),
            ];
        }

        usort($result, fn (array $a, array $b): int => strcmp($a['unit'], $b['unit']));

        return [
            'from' => $awal->toDateString(),
            'to' => $akhir->toDateString(),
            'units' => $result,
            'totals' => [
                'revenue' => $totalPendapatan,
                'expense' => $totalBeban,
                'result' => Money::sub($totalPendapatan, $totalBeban),
            ],
        ];
    }

    /**
     * Laporan dana: saldo awal, penerimaan, penggunaan, saldo akhir per dana.
     *
     * Penerimaan = sisi kredit bersih, penggunaan = sisi debet bersih pada baris
     * yang bertanda dana. Akun pengurang dana bebas dilaporkan terpisah supaya
     * beban bersama tidak membuat dana bebas tampak lebih besar dari kenyataan.
     *
     * @return array{from: string, to: string, funds: list<array<string, mixed>>, totals: array<string, string>}
     */
    public function fundStatement(?string $from = null, ?string $to = null): array
    {
        $awal = Carbon::parse($from ?? now()->startOfYear()->toDateString())->startOfDay();
        $akhir = Carbon::parse($to ?? now()->toDateString())->endOfDay();

        $funds = FinanceFund::query()->orderBy('kode_dana')->get();
        $kontra = FinanceFundContraAccount::query()->get()->groupBy('kode_dana');

        $penerimaanSebelum = $this->mutasiDana(null, $awal->copy()->subDay()->endOfDay());
        $penerimaan = $this->mutasiDana($awal, $akhir);
        $arusKas = $this->arusKasDana($awal, $akhir);

        $hasil = [];
        $totalMasuk = '0.00';
        $totalKeluar = '0.00';
        $totalSaldo = '0.00';

        foreach ($funds as $fund) {
            $inSebelum = $penerimaanSebelum[$fund->kode_dana]['in'] ?? '0.00';
            $outSebelum = $penerimaanSebelum[$fund->kode_dana]['out'] ?? '0.00';
            $saldoAwal = Money::sub($inSebelum, $outSebelum);

            $in = $penerimaan[$fund->kode_dana]['in'] ?? '0.00';
            $out = $penerimaan[$fund->kode_dana]['out'] ?? '0.00';
            $saldoAkhir = Money::add($saldoAwal, Money::sub($in, $out));

            $totalMasuk = Money::add($totalMasuk, $in);
            $totalKeluar = Money::add($totalKeluar, $out);
            $totalSaldo = Money::add($totalSaldo, $saldoAkhir);

            $hasil[] = [
                'kode_dana' => $fund->kode_dana,
                'nama_dana' => $fund->nama_dana,
                'jenis' => $fund->jenis,
                'jenis_label' => $fund->labelJenis(),
                'peruntukan' => $fund->peruntukan,
                'status' => $fund->status,
                'opening' => $saldoAwal,
                'received' => $in,
                'used' => $out,
                'balance' => $saldoAkhir,
                'cash_movement' => $arusKas[$fund->kode_dana] ?? '0.00',
                'contra_accounts' => $kontra->get($fund->kode_dana, collect())
                    ->map(fn ($row): array => ['account_id' => $row->account_id, 'keterangan' => $row->keterangan])
                    ->values()
                    ->all(),
            ];
        }

        // Baris tanpa dana tetap dilaporkan: totalnya harus sama dengan buku besar.
        $tanpaDana = $this->mutasiDana($awal, $akhir, hanyaTanpaDana: true);
        $hasil[] = [
            'kode_dana' => null,
            'nama_dana' => 'TANPA DANA',
            'jenis' => null,
            'jenis_label' => 'Belum ditandai',
            'peruntukan' => null,
            'status' => null,
            'opening' => '0.00',
            'received' => $tanpaDana[null]['in'] ?? '0.00',
            'used' => $tanpaDana[null]['out'] ?? '0.00',
            'balance' => Money::sub($tanpaDana[null]['in'] ?? '0.00', $tanpaDana[null]['out'] ?? '0.00'),
            'cash_movement' => $arusKas[0] ?? '0.00',
            'contra_accounts' => [],
        ];

        return [
            'from' => $awal->toDateString(),
            'to' => $akhir->toDateString(),
            'funds' => $hasil,
            'totals' => ['received' => $totalMasuk, 'used' => $totalKeluar, 'balance' => $totalSaldo],
        ];
    }

    /**
     * Mutasi per akun pada rentang tanggal.
     *
     * @return array<int, array{debit: string, credit: string}>
     */
    protected function mutasiPerAkun(?Carbon $from, Carbon $to): array
    {
        $query = DB::table('finance_transaction_lines as l')
            ->join('finance_transactions as t', 't.id', '=', 'l.finance_transaction_id')
            // Kolomnya datetime; batas date-only akan mengecualikan transaksi di
            // hari terakhir rentang — jenis kesalahan yang membuat laporan "kurang
            // satu hari" dan sulit terlihat.
            ->where('t.transaction_date', '<=', $to->copy()->endOfDay()->toDateTimeString())
            ->when($from !== null, fn ($q) => $q->where('t.transaction_date', '>=', $from->copy()->startOfDay()->toDateTimeString()))
            ->groupBy('l.account_id')
            ->select('l.account_id', DB::raw('sum(l.debit) as debit'), DB::raw('sum(l.credit) as credit'));

        $out = [];

        foreach ($query->get() as $row) {
            $out[(int) $row->account_id] = [
                'debit' => Money::of($row->debit),
                'credit' => Money::of($row->credit),
            ];
        }

        return $out;
    }

    /**
     * @return array<string|int, array{in: string, out: string}>
     */
    protected function mutasiDana(?Carbon $from, Carbon $to, bool $hanyaTanpaDana = false): array
    {
        // Penerimaan = sisi kredit bersih akun PENDAPATAN; penggunaan = sisi debet
        // bersih akun BEBAN. Keduanya dibaca dari SIFAT AKUN, bukan dari sisi
        // debet/kredit baris kas: kalau memakai sisi baris, pembayaran beban dari
        // kas berdana akan terhitung dua kali — sekali sebagai penggunaan, sekali
        // lagi sebagai penerimaan — dan saldo dana tampak lebih besar dari uangnya.
        $query = DB::table('finance_transaction_lines as l')
            ->join('finance_transactions as t', 't.id', '=', 'l.finance_transaction_id')
            ->join('finance_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('t.transaction_date', '<=', $to->copy()->endOfDay()->toDateTimeString())
            ->when($from !== null, fn ($q) => $q->where('t.transaction_date', '>=', $from->copy()->startOfDay()->toDateTimeString()))
            ->whereIn(DB::raw('substr(a.code, 1, 1)'), [CoaHierarchy::PENDAPATAN, CoaHierarchy::BEBAN])
            ->groupBy('l.kode_dana')
            ->select(
                'l.kode_dana',
                DB::raw("sum(case when substr(a.code, 1, 1) = '".CoaHierarchy::PENDAPATAN."' then l.credit - l.debit else 0 end) as masuk"),
                DB::raw("sum(case when substr(a.code, 1, 1) = '".CoaHierarchy::BEBAN."' then l.debit - l.credit else 0 end) as keluar"),
            );

        if ($hanyaTanpaDana) {
            $query->whereNull('l.kode_dana');
        }

        $out = [];

        foreach ($query->get() as $row) {
            $key = $row->kode_dana ?? null;
            $out[$key === null ? 0 : $key] = ['in' => Money::of($row->masuk), 'out' => Money::of($row->keluar)];
        }

        return $out;
    }

    /**
     * Mutasi kas/bank yang menempel pada dana.
     *
     * Dilaporkan TERPISAH dari penerimaan/penggunaan: ia menjawab "berapa uang
     * yang ada", bukan "berapa yang diperoleh & dipakai". Mencampur keduanya
     * membuat laporan dana tidak dapat direkonsiliasi dengan buku besar kas.
     *
     * @return array<string|int, string>
     */
    protected function arusKasDana(?Carbon $from, Carbon $to): array
    {
        $query = DB::table('finance_transaction_lines as l')
            ->join('finance_transactions as t', 't.id', '=', 'l.finance_transaction_id')
            ->join('finance_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('t.transaction_date', '<=', $to->copy()->endOfDay()->toDateTimeString())
            ->when($from !== null, fn ($q) => $q->where('t.transaction_date', '>=', $from->copy()->startOfDay()->toDateTimeString()))
            ->where(DB::raw('substr(a.code, 1, 1)'), CoaHierarchy::ASET)
            ->groupBy('l.kode_dana')
            ->select('l.kode_dana', DB::raw('sum(l.debit - l.credit) as bersih'));

        $out = [];

        foreach ($query->get() as $row) {
            $key = $row->kode_dana ?? null;
            $out[$key === null ? 0 : $key] = Money::of($row->bersih);
        }

        return $out;
    }

    protected function saldoAwal(?FinanceOpeningBalance $opening, ?array $sebelum): string
    {
        $dariMutasiSebelum = $sebelum === null
            ? '0.00'
            : Money::sub($sebelum['debit'], $sebelum['credit']);

        if ($opening === null) {
            return $dariMutasiSebelum;
        }

        $dariSaldoAwal = $opening->jenis_saldo === 'debet'
            ? Money::of($opening->saldo)
            : Money::neg($opening->saldo);

        return Money::add($dariSaldoAwal, $dariMutasiSebelum);
    }
}
