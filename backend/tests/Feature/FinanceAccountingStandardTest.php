<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceBusinessUnit;
use App\Models\FinanceCashAccount;
use App\Models\FinanceFund;
use App\Models\FinanceFundAccount;
use App\Models\FinanceJournalVoidRequest;
use App\Models\FinanceOpeningBalance;
use App\Models\FinancePeriod;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\Finance\Accounting\FundService;
use App\Services\Finance\Accounting\JournalVoidService;
use App\Services\Finance\Accounting\OpeningBalanceService;
use App\Services\Finance\Accounting\PeriodReopenService;
use App\Services\Finance\Accounting\StandardReportService;
use App\Services\Finance\Accounting\UnitResolver;
use App\Services\Finance\FinancePeriodService;
use App\Services\Finance\FinanceSettingsService;
use App\Services\Finance\FinancialTransactionEngine;
use App\Support\Accounting\CoaHierarchy;
use App\Support\Accounting\SumberModul;
use App\Support\Money;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Standar akuntansi (rujukan: al-wafi) — aturan yang membuat pembukuan sah.
 *
 * Uji ini menjaga enam hal yang paling mudah rusak tanpa terlihat:
 *   1. uang dibandingkan TEPAT (BCMath), bukan float bertoleransi;
 *   2. aturan double-entry menolak jurnal satu sisi / sejenis / tidak seimbang;
 *   3. periode tertutup menolak posting, dan hanya boleh dibuka lewat permohonan
 *      yang diputuskan orang lain;
 *   4. dana terikat hanya membatasi baris BEBAN, bukan kasnya;
 *   5. unit bisnis diwarisi bertingkat, dan baris NERACA selalu memakai unit
 *      penampung;
 *   6. saldo awal & pembatalan jurnal terkunci setelah diposting, dan koreksinya
 *      meninggalkan jejak yang dapat ditelusuri.
 */
class FinanceAccountingStandardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceConfigSeeder::class);
    }

    // ------------------------------------------------------------------ uang

    public function test_uang_dibandingkan_tepat_tanpa_toleransi_float(): void
    {
        $kas = $this->akunDaun('1');
        $pendapatan = $this->akunDaun('4');
        $beban = $this->akunDaun('5');
        $aktor = $this->aktor();

        // 0,10 + 0,20 = 0,30 tepat. Dengan float, penjumlahan ini menghasilkan
        // 0,30000000000000004 — dan dulu ditutupi toleransi 0,005.
        $jur = $this->engine()->post('adjustment', 'Uji presisi', [
            ['account_id' => $kas, 'debit' => 0.10],
            ['account_id' => $pendapatan, 'debit' => 0.20],
            ['account_id' => $beban, 'credit' => 0.30],
        ], ['date' => now(), 'created_by' => $aktor->id, 'sumber_modul' => SumberModul::JURNAL_UMUM]);

        $this->assertSame('0.30', (string) $jur->total_debit);
        $this->assertSame('0.30', (string) $jur->total_credit);

        // Selisih satu sen sekalipun adalah ketidakseimbangan — bukan dibulatkan.
        $this->expectException(ValidationException::class);
        $this->engine()->post('adjustment', 'Tidak seimbang', [
            ['account_id' => $kas, 'debit' => 100.00],
            ['account_id' => $beban, 'credit' => 99.99],
        ], ['date' => now(), 'created_by' => $aktor->id]);
    }

    public function test_aturan_double_entry_menolak_jurnal_tidak_sah(): void
    {
        $kas = $this->akunDaun('1');
        $beban = $this->akunDaun('5');
        $aktor = $this->aktor();

        // Satu baris saja.
        try {
            $this->engine()->post('adjustment', 'Satu baris', [
                ['account_id' => $kas, 'debit' => 10],
            ], ['date' => now(), 'created_by' => $aktor->id]);
            $this->fail('Jurnal satu baris seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('minimal 2 baris', implode(' ', $e->errors()['lines'] ?? ['']));
        }

        // Debet & kredit sekaligus pada satu baris.
        try {
            $this->engine()->post('adjustment', 'Dua sisi', [
                ['account_id' => $kas, 'debit' => 10, 'credit' => 10],
                ['account_id' => $beban, 'credit' => 10],
            ], ['date' => now(), 'created_by' => $aktor->id]);
            $this->fail('Baris dua sisi seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('debet dan kredit sekaligus', implode(' ', $e->errors()['lines'] ?? ['']));
        }

        // Nilai negatif.
        $this->expectException(ValidationException::class);
        $this->engine()->post('adjustment', 'Negatif', [
            ['account_id' => $kas, 'debit' => -10],
            ['account_id' => $beban, 'credit' => 10],
        ], ['date' => now(), 'created_by' => $aktor->id]);
    }

    public function test_modul_asal_jurnal_wajib_terdaftar(): void
    {
        $kas = $this->akunDaun('1');
        $beban = $this->akunDaun('5');

        $this->expectException(ValidationException::class);
        $this->engine()->post('adjustment', 'Modul ngawur', [
            ['account_id' => $kas, 'debit' => 10],
            ['account_id' => $beban, 'credit' => 10],
        ], ['date' => now(), 'sumber_modul' => 'ModulTidakDikenal']);
    }

    public function test_akar_kelompok_akun_diturunkan_dari_kode(): void
    {
        $this->assertSame('1', CoaHierarchy::dariKode('1100'));
        $this->assertSame('5', CoaHierarchy::dariKode('5100'));
        $this->assertTrue(CoaHierarchy::isBeban('5100'));
        $this->assertTrue(CoaHierarchy::isNeraca('2100'));
        $this->assertFalse(CoaHierarchy::isNeraca('4100'));
        $this->assertNull(CoaHierarchy::dariKode('X-1'));
    }

    // --------------------------------------------------------------- periode

    public function test_periode_tertutup_menolak_posting_dan_hanya_dibuka_lewat_permohonan(): void
    {
        $kas = $this->akunDaun('1');
        $beban = $this->akunDaun('5');
        $tanggal = now();
        $periode = $this->pastikanPeriode($tanggal);

        $pengaju = $this->aktor('pengaju@sekolah.test');
        $pemutus = $this->aktor('pemutus@sekolah.test');

        app(FinancePeriodService::class)->close($periode, $pengaju);

        // Tutup buku mengikat: tidak ada toleransi hari.
        try {
            $this->engine()->post('adjustment', 'Setelah tutup buku', [
                ['account_id' => $kas, 'debit' => 10],
                ['account_id' => $beban, 'credit' => 10],
            ], ['date' => $tanggal, 'created_by' => $pengaju->id]);
            $this->fail('Posting ke periode tertutup seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ditutup', implode(' ', $e->errors()['date'] ?? ['']));
        }

        $reopen = app(PeriodReopenService::class);
        $permohonan = $reopen->request($periode->refresh(), 'Ada beban bulan lalu yang belum tercatat.', $pengaju);

        // Pemohon tidak memutuskan permohonannya sendiri.
        try {
            $reopen->decide($permohonan, true, $pengaju);
            $this->fail('Pengaju tidak boleh memutuskan permohonannya sendiri.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('decided_by', $e->errors());
        }

        $reopen->decide($permohonan, true, $pemutus, 'Disetujui untuk koreksi beban.');
        $this->assertTrue($periode->refresh()->isOpen());

        // Sesudah dibuka, jurnal yang sama dapat diposting — dan terlacak.
        $jur = $this->engine()->post('adjustment', 'Koreksi beban bulan lalu', [
            ['account_id' => $kas, 'debit' => 10],
            ['account_id' => $beban, 'credit' => 10],
        ], ['date' => $tanggal, 'created_by' => $pemutus->id]);

        $this->assertSame(FinancialTransaction::STATUS_POSTED, $jur->status);
        $this->assertSame('approved', $permohonan->refresh()->status);
    }

    // ------------------------------------------------------------------ dana

    public function test_dana_terikat_hanya_membatasi_baris_beban(): void
    {
        $kas = $this->akunDaun('1');
        $bebanDiizinkan = $this->akunDaun('5');
        $bebanLain = $this->akunDaun('5', selain: $bebanDiizinkan);

        $fund = app(FundService::class)->save([
            'kode_dana' => 'WAKAF-1',
            'nama_dana' => 'Wakaf Pembangunan',
            'jenis' => 'wakaf',
            'peruntukan' => 'Pembangunan gedung & sarana',
        ]);
        app(FundService::class)->syncAccounts($fund, [$bebanDiizinkan]);

        $aktor = $this->aktor();

        // Beban di dalam peruntukan: diterima.
        $sah = $this->engine()->post('expense', 'Bahan bangunan', [
            ['account_id' => $bebanDiizinkan, 'debit' => 500],
            ['account_id' => $kas, 'credit' => 500],
        ], ['date' => now(), 'created_by' => $aktor->id, 'kode_dana' => 'WAKAF-1']);

        $this->assertNotNull($sah->id);
        $this->assertSame('WAKAF-1', $sah->lines()->first()->kode_dana);

        // Beban di luar peruntukan: ditolak, dengan penjelasan yang menyebut peruntukannya.
        try {
            $this->engine()->post('expense', 'Gaji (bukan peruntukan wakaf)', [
                ['account_id' => $bebanLain, 'debit' => 500],
                ['account_id' => $kas, 'credit' => 500],
            ], ['date' => now(), 'created_by' => $aktor->id, 'kode_dana' => 'WAKAF-1']);
            $this->fail('Beban di luar peruntukan dana seharusnya ditolak.');
        } catch (ValidationException $e) {
            $pesan = implode(' ', $e->errors()['lines'] ?? ['']);
            $this->assertStringContainsString('peruntukan', $pesan);
            $this->assertStringContainsString('Wakaf Pembangunan', $pesan);
        }

        // Aset (kas) berdana sama: TIDAK dibatasi — kas menumpang rekening yang sama.
        $kasBerdana = $this->engine()->post('adjustment', 'Penerimaan wakaf', [
            ['account_id' => $kas, 'debit' => 1000],
            ['account_id' => $this->akunDaun('3'), 'credit' => 1000],
        ], ['date' => now(), 'created_by' => $aktor->id, 'kode_dana' => 'WAKAF-1']);

        $this->assertSame('WAKAF-1', $kasBerdana->lines()->where('account_id', $kas)->first()->kode_dana);
    }

    public function test_dana_nonaktif_dan_tidak_dikenal_ditolak(): void
    {
        $kas = $this->akunDaun('1');
        $beban = $this->akunDaun('5');
        $aktor = $this->aktor();

        app(FundService::class)->save([
            'kode_dana' => 'DANA-MATI',
            'nama_dana' => 'Dana Lama',
            'jenis' => 'terikat',
            'peruntukan' => 'Kegiatan lama',
            'status' => 'nonaktif',
        ]);

        foreach (['DANA-MATI' => 'nonaktif', 'DANA-HANTU' => 'tidak ditemukan'] as $kode => $harapan) {
            try {
                $this->engine()->post('expense', 'Uji dana', [
                    ['account_id' => $beban, 'debit' => 10],
                    ['account_id' => $kas, 'credit' => 10],
                ], ['date' => now(), 'created_by' => $aktor->id, 'kode_dana' => $kode]);
                $this->fail("Dana {$kode} seharusnya ditolak.");
            } catch (ValidationException $e) {
                $this->assertStringContainsString($harapan, implode(' ', $e->errors()['lines'] ?? ['']));
            }
        }
    }

    // ------------------------------------------------------------------ unit

    public function test_unit_diwarisi_bertingkat_dan_baris_neraca_memakai_unit_penampung(): void
    {
        $units = app(\App\Services\Finance\Accounting\BusinessUnitService::class);
        $units->save(['code' => 'SEKOLAH', 'name' => 'Sekolah Induk']);
        $units->save(['code' => 'ASRAMA', 'name' => 'Asrama', 'parent_code' => 'SEKOLAH']);
        $units->save(['code' => 'KOPERASI', 'name' => 'Koperasi']);

        $kasAkun = $this->akunDaun('1');
        $pendapatan = $this->akunDaun('4');
        $beban = $this->akunDaun('5');
        $aktor = $this->aktor();

        // Unit penampung: seluruh liabilitas & rekening kas/bank.
        app(FinanceSettingsService::class)->update(['container_unit' => 'SEKOLAH']);
        app(UnitResolver::class)->forget();

        $rekeningKas = $this->rekeningKas($kasAkun);
        $this->assertNotNull($rekeningKas, 'Uji ini memerlukan satu rekening kas yang menunjuk akun kas.');

        // Tanpa unit pada dokumen: unit baris tetap kosong.
        $tanpaUnit = $this->engine()->post('adjustment', 'Tanpa unit', [
            ['account_id' => $pendapatan, 'debit' => 10],
            ['account_id' => $beban, 'credit' => 10],
        ], ['date' => now(), 'created_by' => $aktor->id]);

        $this->assertNull($tanpaUnit->lines()->where('account_id', $pendapatan)->first()->kode_unit);

        // Unit dokumen diwarisi seluruh baris…
        $unitDokumen = $this->engine()->post('adjustment', 'Unit dokumen', [
            ['account_id' => $pendapatan, 'debit' => 10],
            ['account_id' => $beban, 'credit' => 10],
        ], ['date' => now(), 'created_by' => $aktor->id, 'kode_unit' => 'KOPERASI']);

        $this->assertSame('KOPERASI', $unitDokumen->lines()->where('account_id', $pendapatan)->first()->kode_unit);

        // …tetapi unit pada barisnya sendiri menang atas unit dokumen.
        $unitBaris = $this->engine()->post('adjustment', 'Unit per baris', [
            ['account_id' => $pendapatan, 'debit' => 10, 'kode_unit' => 'ASRAMA'],
            ['account_id' => $beban, 'credit' => 10],
        ], ['date' => now(), 'created_by' => $aktor->id, 'kode_unit' => 'KOPERASI']);

        $this->assertSame('ASRAMA', $unitBaris->lines()->where('account_id', $pendapatan)->first()->kode_unit);
        $this->assertSame('KOPERASI', $unitBaris->lines()->where('account_id', $beban)->first()->kode_unit);

        // Baris kas mengabaikan unit dokumen & unit baris, selalu unit penampung.
        $kasBerdimensi = $this->engine()->post('adjustment', 'Kas selalu unit penampung', [
            ['account_id' => $kasAkun, 'debit' => 10, 'kode_unit' => 'ASRAMA'],
            ['account_id' => $pendapatan, 'credit' => 10],
        ], ['date' => now(), 'created_by' => $aktor->id, 'kode_unit' => 'KOPERASI']);

        $this->assertSame('SEKOLAH', $kasBerdimensi->lines()->where('account_id', $kasAkun)->first()->kode_unit);

        // Unit bawaan modul: dipakai hanya bila unit dokumen tidak disebut.
        $units->setDefault(SumberModul::TAGIHAN_SPP, 'ASRAMA');
        app(UnitResolver::class)->forget();

        $unitBawaan = $this->engine()->post('invoice', 'Tagihan SPP', [
            ['account_id' => $pendapatan, 'debit' => 10],
            ['account_id' => $beban, 'credit' => 10],
        ], ['date' => now(), 'created_by' => $aktor->id, 'sumber_modul' => SumberModul::TAGIHAN_SPP]);

        $this->assertSame('ASRAMA', $unitBawaan->lines()->where('account_id', $pendapatan)->first()->kode_unit);
    }

    public function test_nama_akun_disnapshot_pada_baris_jurnal(): void
    {
        $kas = $this->akunDaun('1');
        $beban = $this->akunDaun('5');
        $namaAsli = FinanceAccount::query()->whereKey($beban)->value('name');

        $jur = $this->engine()->post('expense', 'Snapshot nama', [
            ['account_id' => $beban, 'debit' => 10],
            ['account_id' => $kas, 'credit' => 10],
        ], ['date' => now(), 'created_by' => $this->aktor()->id]);

        $this->assertSame($namaAsli, $jur->lines()->where('account_id', $beban)->first()->account_name);

        // Nama akun diubah kelak: baris lama tetap terbaca dengan nama saat itu.
        FinanceAccount::query()->whereKey($beban)->update(['name' => 'Nama Baru Setelahnya']);

        $this->assertSame($namaAsli, $jur->lines()->where('account_id', $beban)->first()->account_name);
    }

    // ----------------------------------------------------- saldo awal & void

    public function test_saldo_awal_wajib_seimbang_dan_terkunci_setelah_diposting(): void
    {
        $kas = $this->akunDaun('1');
        $ekuitas = $this->akunDaun('3');
        $aktor = $this->aktor();

        $opening = app(OpeningBalanceService::class);

        // Belum seimbang: posting ditolak, dan selisihnya disebut.
        $opening->save([
            ['account_id' => $kas, 'jenis_saldo' => 'debet', 'saldo' => 1000],
        ], $aktor);

        $this->assertFalse($opening->summary()['balanced']);

        try {
            $opening->post($aktor);
            $this->fail('Saldo awal yang belum seimbang seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('belum seimbang', implode(' ', $e->errors()['opening'] ?? ['']));
        }

        // Dilengkapi sisi ekuitas: seimbang, lalu diposting menjadi jurnal pembuka.
        $opening->save([['account_id' => $ekuitas, 'jenis_saldo' => 'kredit', 'saldo' => 1000]], $aktor);
        $this->assertTrue($opening->summary()['balanced']);

        $jur = $opening->post($aktor);

        $this->assertSame(FinancialTransaction::TYPE_OPENING, $jur->type);
        $this->assertSame(SumberModul::SALDO_AWAL, $jur->sumber_modul);
        $this->assertSame('1000.00', (string) $jur->total_debit);
        $this->assertTrue(FinanceOpeningBalance::query()->posted()->exists());

        // Setelah diposting: rancangan tidak dapat diubah lagi.
        try {
            $opening->save([['account_id' => $kas, 'jenis_saldo' => 'debet', 'saldo' => 999]], $aktor);
            $this->fail('Saldo awal yang sudah diposting seharusnya terkunci.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('opening', $e->errors());
        }
    }

    public function test_pembatalan_jurnal_lewat_permohonan_menyalin_dimensi_dan_menandai_void(): void
    {
        $units = app(\App\Services\Finance\Accounting\BusinessUnitService::class);
        $units->save(['code' => 'KOPERASI', 'name' => 'Koperasi']);

        $fund = app(FundService::class)->save([
            'kode_dana' => 'BEBAS-1', 'nama_dana' => 'Dana Bebas', 'jenis' => 'bebas',
        ]);

        $kas = $this->akunDaun('1');
        $pendapatan = $this->akunDaun('4');
        $pengaju = $this->aktor('pengaju2@sekolah.test');
        $pemutus = $this->aktor('pemutus2@sekolah.test');

        $jur = $this->engine()->post('revenue', 'Penerimaan koperasi', [
            ['account_id' => $kas, 'debit' => 250],
            ['account_id' => $pendapatan, 'credit' => 250],
        ], [
            'date' => now(),
            'created_by' => $pengaju->id,
            'kode_unit' => 'KOPERASI',
            'kode_dana' => 'BEBAS-1',
        ]);

        $this->assertSame('KOPERASI', $jur->lines()->first()->kode_unit);
        $this->assertSame('BEBAS-1', $jur->lines()->first()->kode_dana);

        $voids = app(JournalVoidService::class);
        $permohonan = $voids->request($jur, 'Salah akun pendapatan.', $pengaju);

        try {
            $voids->decide($permohonan, true, $pengaju);
            $this->fail('Pengaju tidak boleh menyetujui pembatalan jurnalnya sendiri.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('decided_by', $e->errors());
        }

        $voids->decide($permohonan, true, $pemutus, 'Disetujui.');

        $asli = $jur->refresh();
        $this->assertSame(FinancialTransaction::STATUS_REVERSED, $asli->status);
        $this->assertNotNull($asli->reversed_by_transaction_id);

        $pembalik = FinancialTransaction::query()->findOrFail($asli->reversed_by_transaction_id);

        $this->assertSame('250.00', (string) $pembalik->total_debit);
        $this->assertSame('250.00', (string) $pembalik->total_credit);
        // Debet & kredit ditukar, dan dimensinya disalin persis.
        $this->assertSame('250.00', (string) $pembalik->lines()->where('account_id', $kas)->value('credit'));
        $this->assertSame('250.00', (string) $pembalik->lines()->where('account_id', $pendapatan)->value('debit'));
        $this->assertSame('KOPERASI', $pembalik->lines()->first()->kode_unit);
        $this->assertSame('BEBAS-1', $pembalik->lines()->first()->kode_dana);
        $this->assertSame('approved', $permohonan->refresh()->status);
    }

    // -------------------------------------------------------------- laporan

    public function test_laporan_neraca_saldo_seimbang_dan_laba_rugi_serta_dana_terhitung(): void
    {
        $units = app(\App\Services\Finance\Accounting\BusinessUnitService::class);
        $units->save(['code' => 'SEKOLAH', 'name' => 'Sekolah Induk']);
        $units->save(['code' => 'ASRAMA', 'name' => 'Asrama']);

        app(FundService::class)->save(['kode_dana' => 'BEBAS-1', 'nama_dana' => 'Dana Bebas', 'jenis' => 'bebas']);

        $kas = $this->akunDaun('1');
        $pendapatan = $this->akunDaun('4');
        $beban = $this->akunDaun('5');
        $aktor = $this->aktor();

        $this->engine()->post('revenue', 'SPP bulan ini', [
            ['account_id' => $kas, 'debit' => 1000],
            ['account_id' => $pendapatan, 'credit' => 1000],
        ], ['date' => now(), 'created_by' => $aktor->id, 'kode_unit' => 'ASRAMA', 'kode_dana' => 'BEBAS-1']);

        $this->engine()->post('expense', 'Beban asrama', [
            ['account_id' => $beban, 'debit' => 400],
            ['account_id' => $kas, 'credit' => 400],
        ], ['date' => now(), 'created_by' => $aktor->id, 'kode_unit' => 'ASRAMA', 'kode_dana' => 'BEBAS-1']);

        $reports = app(StandardReportService::class);

        $tb = $reports->trialBalance();
        $this->assertTrue($tb['balanced'], 'Neraca saldo harus seimbang (total debet == total kredit).');
        $this->assertSame(Money::of($tb['totals']['debit']), $tb['totals']['debit']);

        $labarugi = $reports->profitLossByUnit();
        $asrama = collect($labarugi['units'])->firstWhere('unit', 'ASRAMA');

        $this->assertNotNull($asrama, 'Laba rugi harus memuat unit ASRAMA.');
        $this->assertSame('1000.00', $asrama['revenue']);
        $this->assertSame('400.00', $asrama['expense']);
        $this->assertSame('600.00', $asrama['result']);
        // Total laporan == total baris buku besar, termasuk baris tanpa unit.
        $this->assertSame(Money::sub($labarugi['totals']['revenue'], $labarugi['totals']['expense']), $labarugi['totals']['result']);

        $dana = $reports->fundStatement();
        $bebas = collect($dana['funds'])->firstWhere('kode_dana', 'BEBAS-1');

        $this->assertNotNull($bebas);
        $this->assertSame('1000.00', $bebas['received']);
        $this->assertSame('400.00', $bebas['used']);
        $this->assertSame('600.00', $bebas['balance']);
        // Baris tanpa dana tetap dilaporkan agar totalnya sama dengan buku besar.
        $this->assertTrue(collect($dana['funds'])->contains(fn (array $row): bool => $row['kode_dana'] === null));
    }

    // --------------------------------------------------------------- bantuan

    protected function engine(): FinancialTransactionEngine
    {
        return app(FinancialTransactionEngine::class);
    }

    protected function aktor(string $email = 'akuntan@sekolah.test'): User
    {
        return User::query()->firstWhere('email', $email)
            ?? User::factory()->create(['email' => $email, 'is_active' => true]);
    }

    /** Akun rincian (leaf) pertama dengan akar kelompok tertentu. */
    protected function akunDaun(string $akar, ?int $selain = null): int
    {
        $akun = FinanceAccount::query()
            ->where('code', 'like', $akar.'%')
            ->where('is_active', true)
            ->when($selain !== null, fn ($q) => $q->whereKeyNot($selain))
            ->get()
            ->first(fn (FinanceAccount $a): bool => $a->isLeaf());

        $this->assertNotNull($akun, "Tidak menemukan akun daun dengan akar kelompok {$akar} pada COA bawaan.");

        return (int) $akun->id;
    }

    /** Rekening kas yang menunjuk akun COA tertentu (bila ada di data bawaan). */
    protected function rekeningKas(int $accountId): ?FinanceCashAccount
    {
        return FinanceCashAccount::query()->where('account_id', $accountId)->first();
    }

    protected function pastikanPeriode(Carbon $tanggal): FinancePeriod
    {
        $periode = FinancePeriod::forDate($tanggal);

        if ($periode !== null) {
            return $periode;
        }

        return FinancePeriod::query()->create([
            'code' => $tanggal->format('Y-m'),
            'name' => 'Periode '.$tanggal->format('m/Y'),
            'type' => FinancePeriod::TYPE_MONTHLY,
            'fiscal_year' => (int) $tanggal->year,
            'start_date' => $tanggal->copy()->startOfMonth()->toDateString(),
            'end_date' => $tanggal->copy()->endOfMonth()->toDateString(),
            'status' => FinancePeriod::STATUS_OPEN,
        ]);
    }
}
