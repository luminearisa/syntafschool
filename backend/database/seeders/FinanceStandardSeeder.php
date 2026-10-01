<?php

namespace Database\Seeders;

use App\Models\FinanceBusinessUnit;
use App\Models\FinanceFund;
use App\Models\FinanceUnitDefault;
use App\Services\Finance\FinanceSettingsService;
use App\Support\Accounting\SumberModul;
use Illuminate\Database\Seeder;

/**
 * Data awal akuntansi standar: unit bisnis, unit bawaan per modul, dana bebas,
 * dan unit penampung.
 *
 * Idempoten (firstOrCreate), jadi aman dijalankan berulang. Yang diisikan adalah
 * hal yang tanpanya fitur ini tidak dapat dipakai sama sekali:
 *   - unit penampung, karena tanpa itu laporan per unit tidak punya tempat
 *     menaruh baris neraca;
 *   - satu dana bebas, karena DanaPolicy menolak kode dana yang tidak dikenal;
 *   - pemetaan unit bawaan untuk modul jurnal yang paling sering dipakai.
 *
 * Sekolah dapat menyesuaikan seluruhnya lewat layar; seeder ini hanya titik awal.
 */
class FinanceStandardSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['code' => 'YAYASAN', 'name' => 'Yayasan (Pusat)', 'parent_code' => null],
            ['code' => 'SEKOLAH', 'name' => 'Sekolah', 'parent_code' => 'YAYASAN'],
            ['code' => 'ASRAMA', 'name' => 'Asrama', 'parent_code' => 'YAYASAN'],
            ['code' => 'KOPERASI', 'name' => 'Koperasi / Unit Usaha', 'parent_code' => 'YAYASAN'],
        ];

        foreach ($units as $unit) {
            FinanceBusinessUnit::query()->firstOrCreate(
                ['code' => $unit['code']],
                [
                    'name' => $unit['name'],
                    'parent_code' => $unit['parent_code'],
                    'level' => $unit['parent_code'] === null ? 1 : 2,
                    'is_active' => true,
                ],
            );
        }

        $defaults = [
            SumberModul::TAGIHAN_SPP => 'SEKOLAH',
            SumberModul::TAGIHAN_LAIN => 'SEKOLAH',
            SumberModul::INVOICE => 'SEKOLAH',
            SumberModul::PEMBAYARAN_SANTRI => 'SEKOLAH',
            SumberModul::PPDB => 'SEKOLAH',
            SumberModul::PENGGAJIAN => 'YAYASAN',
            SumberModul::BEBAN_OPERASIONAL => 'YAYASAN',
            SumberModul::KAS_MASUK => 'YAYASAN',
            SumberModul::KAS_KELUAR => 'YAYASAN',
            SumberModul::MUTASI_DOMPET => 'SEKOLAH',
            SumberModul::DENDA_PERPUSTAKAAN => 'SEKOLAH',
            SumberModul::BIAYA_KLINIK => 'SEKOLAH',
        ];

        foreach ($defaults as $modul => $unit) {
            FinanceUnitDefault::query()->firstOrCreate(
                ['sumber_modul' => $modul],
                ['business_unit_code' => $unit],
            );
        }

        FinanceFund::query()->firstOrCreate(
            ['kode_dana' => 'DANA-BEBAS'],
            [
                'nama_dana' => 'Dana Bebas (Umum)',
                'jenis' => 'bebas',
                'peruntukan' => 'Belanja operasional rutin yang tidak terikat sumbernya',
                'status' => 'aktif',
            ],
        );

        // Unit penampung: seluruh baris neraca (liabilitas & rekening kas/bank)
        // dipusatkan ke sini, karena neraca hanya bermakna di tingkat yayasan.
        $settings = app(FinanceSettingsService::class);

        if (empty($settings->get('container_unit'))) {
            $settings->update(['container_unit' => 'YAYASAN']);
        }
    }
}
