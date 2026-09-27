<?php

namespace Database\Seeders;

use App\Models\Bank;
use App\Models\FinanceAccount;
use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceCostCenter;
use App\Models\FinanceFeeType;
use App\Models\FinancePeriod;
use App\Services\Finance\FinanceNumberingService;
use App\Services\Finance\FinanceSettingsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Konfigurasi dasar modul Finance/Keuangan.
 *
 * Titik awal, bukan satu-satunya sumber kebenaran: COA, cost center, fee type,
 * kas, dan bank semuanya bisa disunting bendahara lewat endpoint ADMIN. Karena
 * itu penulisannya `firstOrCreate` pada kunci unik (code), aman dijalankan
 * berulang tanpa menimpa hasil kerja petugas.
 *
 * Yang dibuat:
 * - Chart of Accounts default (§5) — akun sistem (is_system) agar ledger utuh;
 * - cost center dasar (sekolah, pesantren, overhead) (§7);
 * - jenis tagihan (§10) dipetakan ke akun pendapatan COA;
 * - satu kas operasional + satu rekening bank (§39, §40) tanpa saldo awal palsu;
 * - periode fiskal bulanan tahun berjalan (§48) supaya ledger bisa memposting;
 * - konfigurasi penomoran dokumen (§73) lewat FinanceNumberingService.
 *
 * Saldo awal TIDAK diisi angka: modul harus hidup tanpa data contoh yang
 * mengacaukan neraca. Opening balance diinput bendahara lewat endpoint khusus.
 */
class FinanceConfigSeeder extends Seeder
{
    /** COA default (§5): code => [name, type, parentCode|null]. */
    protected const COA = [
        // 1 — Aset
        ['1000', 'Aset', 'asset', null],
        ['1100', 'Kas', 'asset', '1000'],
        ['1200', 'Bank', 'asset', '1000'],
        // Dana pihak ketiga yang belum settle ke rekening sekolah: uang gateway/VA
        // yang sudah diterima santri tapi belum masuk bank (§18 Phase 3).
        ['1250', 'Dana dalam Penyelesaian (Gateway)', 'asset', '1000'],
        ['1300', 'Piutang (Tagihan Santri)', 'asset', '1000'],
        ['1400', 'Persediaan', 'asset', '1000'],
        // 2 — Kewajiban
        ['2000', 'Kewajiban', 'liability', null],
        ['2100', 'Hutang', 'liability', '2000'],
        ['2200', 'Titipan / Deposit Santri', 'liability', '2000'],
        // 3 — Ekuitas
        ['3000', 'Ekuitas', 'equity', null],
        ['3100', 'Saldo Awal (Opening Balance)', 'equity', '3000'],
        // 4 — Pendapatan
        ['4000', 'Pendapatan', 'revenue', null],
        ['4100', 'Pendapatan SPP / Tuition', 'revenue', '4000'],
        ['4200', 'Pendapatan Asrama / Boarding', 'revenue', '4000'],
        ['4300', 'Pendapatan Pendaftaran', 'revenue', '4000'],
        ['4400', 'Pendapatan Perpustakaan', 'revenue', '4000'],
        ['4500', 'Pendapatan Lain-lain', 'revenue', '4000'],
        // 5 — Pengeluaran
        ['5000', 'Pengeluaran', 'expense', null],
        ['5100', 'Beban Gaji', 'expense', '5000'],
        ['5200', 'Beban Utilitas', 'expense', '5000'],
        ['5300', 'Beban Konsumsi', 'expense', '5000'],
        ['5400', 'Beban Pemeliharaan', 'expense', '5000'],
        ['5500', 'Beban Kantor', 'expense', '5000'],
        ['5600', 'Beban Lain-lain', 'expense', '5000'],
        // Piutang yang dihapus (§33 Phase 3) jadi beban tersendiri, bukan tenggelam
        // di "Beban Lain-lain", agar laporan penagihan bisa menunjukkan berapa
        // tunggakan yang benar-benar dihapus vs masih ditagih.
        ['5700', 'Beban Write-Off Piutang', 'expense', '5000'],
    ];

    public function run(): void
    {
        app(FinanceNumberingService::class)->ensureDefaults();

        $accounts = $this->chartOfAccounts();
        $costCenters = $this->costCenters();
        $this->feeTypes($accounts);
        $this->cashAccounts($accounts, $costCenters);
        $this->bankAccounts($accounts, $costCenters);
        $this->periods();
    }

    /**
     * Chart of Accounts default (§5). Semua akun ditandai `is_system` agar tidak
     * bisa dihapus (ledger harus tetap utuh) dan `normal_balance` diturunkan dari
     * tipe akun (§47).
     *
     * @return array<string, FinanceAccount> keyed by code
     */
    protected function chartOfAccounts(): array
    {
        $accounts = [];

        foreach (self::COA as [$code, $name, $type, $parentCode]) {
            $parent = $parentCode !== null ? ($accounts[$parentCode]->id ?? null) : null;

            $accounts[$code] = FinanceAccount::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'parent_id' => $parent,
                    'normal_balance' => FinanceAccount::NORMAL_BALANCE_FOR_TYPE[$type],
                    'is_system' => true,
                    'is_active' => true,
                ],
            );
        }

        return $accounts;
    }

    /**
     * Cost center dasar (§7): unit pengukur pendapatan/pengeluaran/anggaran.
     *
     * @return array<string, FinanceCostCenter> keyed by code
     */
    protected function costCenters(): array
    {
        $definitions = [
            ['CC-SEKOLAH', 'Sekolah / Madrasah', null],
            ['CC-PESANTREN', 'Pesantren / Asrama', null],
            ['CC-UMUM', 'Umum / Operasional', null],
        ];

        $centers = [];
        foreach ($definitions as [$code, $name, $parentCode]) {
            $centers[$code] = FinanceCostCenter::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'parent_id' => $parentCode !== null ? ($centers[$parentCode]->id ?? null) : null,
                    'is_active' => true,
                ],
            );
        }

        return $centers;
    }

    /**
     * Jenis tagihan (§10) dipetakan ke akun pendapatan COA. `default_amount` 0 —
     * nominal sebenarnya diatur per paket tagihan (§11) atau per santri.
     *
     * @param  array<string, FinanceAccount>  $accounts
     */
    protected function feeTypes(array $accounts): void
    {
        $definitions = [
            // code, name, category, coaCode, frequency
            ['SPP', 'SPP / Tuition', 'sekolah', '4100', 'monthly'],
            ['UANG_GEDUNG', 'Uang Gedung / Pendaftaran', 'sekolah', '4300', 'once'],
            ['DAFTAR_ULANG', 'Daftar Ulang', 'sekolah', '4300', 'once'],
            ['UANG_ASRAMA', 'Uang Asrama / Boarding', 'pesantren', '4200', 'monthly'],
            ['UANG_MAKAN', 'Uang Makan Santri', 'pesantren', '4500', 'monthly'],
            ['SERAGAM', 'Seragam', 'sekolah', '4500', 'once'],
            ['PERLENGKAPAN', 'Perlengkapan Sekolah', 'sekolah', '4500', 'once'],
            ['KEGIATAN', 'Kegiatan / Aktivitas', 'sekolah', '4500', 'yearly'],
            ['PERPUSTAKAAN', 'Perpustakaan', 'sekolah', '4400', 'yearly'],
            ['UJIAN', 'Ujian / Penilaian', 'sekolah', '4500', 'semesterly'],
        ];

        foreach ($definitions as [$code, $name, $category, $coaCode, $frequency]) {
            FinanceFeeType::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'category' => $category,
                    'account_id' => $accounts[$coaCode]->id ?? null,
                    'default_amount' => 0,
                    'frequency' => $frequency,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * Kas operasional (§39). Saldo awal 0 — opening balance diinput bendahara.
     *
     * @param  array<string, FinanceAccount>  $accounts
     * @param  array<string, FinanceCostCenter>  $costCenters
     */
    protected function cashAccounts(array $accounts, array $costCenters): void
    {
        $definitions = [
            ['KAS-SEKOLAH', 'Kas Sekolah', '1100', 'CC-SEKOLAH'],
            ['KAS-PESANTREN', 'Kas Pesantren', '1100', 'CC-PESANTREN'],
        ];

        foreach ($definitions as [$code, $name, $coaCode, $ccCode]) {
            FinanceCashAccount::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'account_id' => $accounts[$coaCode]->id,
                    'cost_center_id' => $costCenters[$ccCode]->id ?? null,
                    'opening_balance' => 0,
                    'current_balance' => 0,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * Rekening bank (§40). `bank_id` menunjuk master `banks` existing (§71 —
     * tidak menduplikasi institusi bank). Bila master belum ada, tetap dibuat
     * tanpa bank_id (nullable) agar modul bisa dipakai.
     *
     * @param  array<string, FinanceAccount>  $accounts
     * @param  array<string, FinanceCostCenter>  $costCenters
     */
    protected function bankAccounts(array $accounts, array $costCenters): void
    {
        $bsi = Bank::query()->where('name', 'like', '%Syariah%')->first() ?? Bank::query()->first();

        $definitions = [
            ['BANK-OPS', 'Bank Operasional Sekolah', '1200', 'CC-SEKOLAH', 'operational'],
        ];

        foreach ($definitions as [$code, $name, $coaCode, $ccCode, $type]) {
            FinanceBankAccount::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'bank_id' => $bsi->id ?? null,
                    'account_number' => null,
                    'account_name' => null,
                    'branch' => null,
                    'account_type' => $type,
                    'finance_account_id' => $accounts[$coaCode]->id,
                    'cost_center_id' => $costCenters[$ccCode]->id ?? null,
                    'opening_balance' => 0,
                    'current_balance' => 0,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * Periode fiskal bulanan (§48) untuk tahun fiskal berjalan. Periode bersifat
     * open; closing (§49) dilakukan bendahara lewat endpoint khusus, bukan di sini.
     *
     * Tahun & bulan awal fiskal dibaca dari settings (default 2026, Juli) supaya
     * konsisten dengan konfigurasi tahun ajaran.
     */
    protected function periods(): void
    {
        $settings = app(FinanceSettingsService::class)->all();
        $fiscalYear = (int) $settings['fiscal_year'];
        $startMonth = max(1, min(12, (int) $settings['fiscal_start_month']));

        $start = Carbon::create($fiscalYear, $startMonth, 1)->startOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $from = $start->copy()->addMonthsNoOverflow($i);
            $to = $from->copy()->endOfMonth();

            FinancePeriod::query()->firstOrCreate(
                ['code' => sprintf('P-%d-%02d', $from->year, $from->month)],
                [
                    'name' => $from->translatedFormat('F Y'),
                    'type' => FinancePeriod::TYPE_MONTHLY,
                    // Ke-12 bulan (mis. Jul 2026–Jun 2027) milik satu tahun fiskal.
                    'fiscal_year' => $fiscalYear,
                    'start_date' => $from->toDateString(),
                    'end_date' => $to->toDateString(),
                    'status' => FinancePeriod::STATUS_OPEN,
                ],
            );
        }
    }
}
