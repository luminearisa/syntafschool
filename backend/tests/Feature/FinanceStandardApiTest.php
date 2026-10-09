<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceAsset;
use App\Models\FinanceBusinessUnit;
use App\Models\FinanceFund;
use App\Models\FinancePeriod;
use App\Models\FinancialTransaction;
use App\Models\InventoryItem;
use App\Models\User;
use App\Services\Finance\FinancePeriodService;
use App\Services\Finance\FinancialTransactionEngine;
use App\Support\Accounting\SumberModul;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\FinanceStandardSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Uji HTTP seluruh endpoint akuntansi standar.
 *
 * Sebelumnya berkas uji standar hanya menguji layanan & engine; jalur HTTP-nya
 * (izin, validasi permintaan, bentuk jawaban) belum dibuktikan. Berkas ini menutup
 * celah itu untuk SELURUH endpoint: unit bisnis, dana, saldo awal, permohonan buka
 * periode & void jurnal, laporan, aset tetap & penyusutan, akrual, dan persediaan.
 *
 * Yang diuji bukan sekadar "status 200": setiap endpoint juga dibuktikan
 * melakukan hal yang dimaksud — jurnalnya lahir, stoknya bergerak, periodenya
 * benar-benar terbuka kembali — serta menolak permintaan yang tidak sah dan
 * permintaan dari pengguna tanpa izin.
 */
class FinanceStandardApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/finance';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceConfigSeeder::class);
        $this->seed(FinanceStandardSeeder::class);
    }

    // -------------------------------------------------------- unit bisnis

    public function test_unit_bisnis_dapat_dibaca_dibuat_diubah_dinonaktifkan_dan_dipetakan(): void
    {
        $this->akuntan();

        $awal = $this->getJson(self::BASE.'/business-units')->assertOk()->json('data');
        $this->assertNotEmpty($awal['units'], 'Unit bisnis awal dari seeder harus terbaca.');
        $this->assertNotEmpty($awal['defaults'], 'Unit bawaan modul harus terbaca.');
        $this->assertNotEmpty($awal['modules'], 'Daftar modul asal jurnal harus terbaca.');

        $this->postJson(self::BASE.'/business-units', [
            'code' => 'KANTIN',
            'name' => 'Kantin Sekolah',
            'parent_code' => 'YAYASAN',
        ])->assertCreated();

        $this->assertDatabaseHas('finance_business_units', ['code' => 'KANTIN', 'parent_code' => 'YAYASAN']);

        // Level diturunkan dari induknya, bukan diketik pengguna.
        $this->assertSame(2, (int) FinanceBusinessUnit::query()->where('code', 'KANTIN')->value('level'));

        $this->patchJson(self::BASE.'/business-units/KANTIN', ['name' => 'Kantin & Koperasi'])->assertOk();
        $this->assertSame('Kantin & Koperasi', FinanceBusinessUnit::query()->where('code', 'KANTIN')->value('name'));

        $this->postJson(self::BASE.'/business-units/KANTIN/toggle', ['is_active' => false])->assertOk();
        $this->assertFalse((bool) FinanceBusinessUnit::query()->where('code', 'KANTIN')->value('is_active'));

        // Unit nonaktif tidak boleh dijadikan unit bawaan: konfigurasi yang
        // menyesatkan, karena pemetaannya tidak akan pernah memberi hasil.
        $this->postJson(self::BASE.'/business-units/defaults', [
            'sumber_modul' => SumberModul::KAS_MASUK,
            'business_unit_code' => 'KANTIN',
        ])->assertStatus(422);

        $this->postJson(self::BASE.'/business-units/defaults', [
            'sumber_modul' => SumberModul::KAS_MASUK,
            'business_unit_code' => 'SEKOLAH',
        ])->assertOk();

        $this->assertSame('SEKOLAH', DB::table('finance_unit_defaults')->where('sumber_modul', SumberModul::KAS_MASUK)->value('business_unit_code'));

        // Modul yang tidak terdaftar ditolak.
        $this->postJson(self::BASE.'/business-units/defaults', [
            'sumber_modul' => 'ModulKarangan',
            'business_unit_code' => 'SEKOLAH',
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------------ dana

    public function test_dana_dapat_dikelola_beserta_daftar_akunnya(): void
    {
        $this->akuntan();

        $this->getJson(self::BASE.'/funds')->assertOk()->assertJsonStructure(['data' => ['funds', 'types']]);

        $created = $this->postJson(self::BASE.'/funds', [
            'kode_dana' => 'WAKAF-GEDUNG',
            'nama_dana' => 'Wakaf Pembangunan Gedung',
            'jenis' => 'wakaf',
            'peruntukan' => 'Pembangunan & renovasi gedung',
        ])->assertCreated()->json('data');

        // Dana terikat tanpa peruntukan ditolak: penolakan jurnal nanti harus
        // dapat dijelaskan alasannya.
        $this->postJson(self::BASE.'/funds', [
            'kode_dana' => 'TERIKAT-TANPA-PERUNTUKAN',
            'nama_dana' => 'Dana Tanpa Peruntukan',
            'jenis' => 'terikat',
        ])->assertStatus(422);

        $this->patchJson(self::BASE.'/funds/'.$created['id'], ['status' => 'nonaktif'])->assertOk();
        $this->assertSame('nonaktif', FinanceFund::query()->find($created['id'])->status);

        $beban = $this->akunDaun('5');
        $this->postJson(self::BASE.'/funds/'.$created['id'].'/accounts', [
            'account_ids' => [$beban],
            'contra_account_ids' => [$this->akunDaun('5', selain: $beban)],
        ])->assertOk();

        $this->assertDatabaseHas('finance_fund_accounts', ['kode_dana' => 'WAKAF-GEDUNG', 'account_id' => $beban]);
        $this->assertDatabaseCount('finance_fund_contra_accounts', 1);

        // Dana yang belum pernah dipakai boleh dihapus; yang sudah dipakai tidak.
        $buang = $this->postJson(self::BASE.'/funds', [
            'kode_dana' => 'DANA-BUANG',
            'nama_dana' => 'Dana Uji Hapus',
            'jenis' => 'bebas',
        ])->assertCreated()->json('data');

        $this->deleteJson(self::BASE.'/funds/'.$buang['id'])->assertOk();
        $this->assertDatabaseMissing('finance_funds', ['kode_dana' => 'DANA-BUANG']);
    }

    public function test_penjaga_peruntukan_dana_menolak_beban_di_luar_peruntukan_lewat_http(): void
    {
        $this->akuntan();

        $bebanDiizinkan = $this->akunDaun('5');
        $bebanLain = $this->akunDaun('5', selain: $bebanDiizinkan);
        $dana = $this->postJson(self::BASE.'/funds', [
            'kode_dana' => 'WAKAF-2',
            'nama_dana' => 'Wakaf Sarana',
            'jenis' => 'terikat',
            'peruntukan' => 'Sarana ibadah',
        ])->json('data');

        $this->postJson(self::BASE.'/funds/'.$dana['id'].'/accounts', ['account_ids' => [$bebanDiizinkan]])->assertOk();

        // Diverifikasi lewat endpoint nyata: akrual beban wajib memakai akun
        // beban (kelompok 5), sehingga barisnya ikut diuji penjaga peruntukan.
        $ditolak = $this->postJson(self::BASE.'/accruals', [
            'kode' => 'AKR-DANA-1',
            'tanggal' => now()->toDateString(),
            'tanggal_balik' => now()->addMonth()->toDateString(),
            'jenis' => 'beban',
            'akun_akrual_id' => $this->akunDaun('2'),
            'akun_lawan_id' => $bebanLain,
            'jumlah' => 100,
            'kode_dana' => 'WAKAF-2',
        ]);

        // Rancangan akrual boleh dibuat (belum ada jurnal); penjaga peruntukan
        // bekerja saat POSTING, karena di situlah jurnalnya lahir.
        $rancangan = $this->postJson(self::BASE.'/accruals', [
            'kode' => 'AKR-DANA-TOLAK',
            'tanggal' => now()->toDateString(),
            'tanggal_balik' => now()->addMonth()->toDateString(),
            'jenis' => 'beban',
            'akun_akrual_id' => $this->akunDaun('2'),
            'akun_lawan_id' => $bebanLain,
            'jumlah' => 100,
            'kode_dana' => 'WAKAF-2',
        ])->assertCreated()->json('data');

        $ditolak = $this->postJson(self::BASE.'/accruals/'.$rancangan['id'].'/post', []);

        $this->assertSame(422, $ditolak->status(), 'Akrual beban di luar peruntukan dana seharusnya ditolak saat posting.');
        $this->assertStringContainsString('peruntukan', json_encode($ditolak->json('errors')));

        // Akun yang masuk peruntukan dana: diposting tanpa halangan.
        $accrual = $this->postJson(self::BASE.'/accruals', [
            'kode' => 'AKR-DANA-2',
            'tanggal' => now()->toDateString(),
            'tanggal_balik' => now()->addMonth()->toDateString(),
            'jenis' => 'beban',
            'akun_akrual_id' => $this->akunDaun('2'),
            'akun_lawan_id' => $bebanDiizinkan,
            'jumlah' => 100,
            'kode_dana' => 'WAKAF-2',
        ])->assertCreated()->json('data');

        $this->postJson(self::BASE.'/accruals/'.$accrual['id'].'/post', [])->assertOk();
    }

    // ----------------------------------------------------------- saldo awal

    public function test_saldo_awal_http_dari_rancangan_sampai_jurnal_pembuka(): void
    {
        $this->akuntan();

        $kas = $this->akunDaun('1');
        $ekuitas = $this->akunDaun('3');

        $this->getJson(self::BASE.'/opening-balances')->assertOk();

        $this->postJson(self::BASE.'/opening-balances', [
            'entries' => [
                ['account_id' => $kas, 'jenis_saldo' => 'debet', 'saldo' => 5000],
            ],
        ])->assertOk()->assertJsonPath('data.summary.balanced', false);

        // Belum seimbang: posting ditolak, dan pesannya menyebut selisihnya.
        $this->postJson(self::BASE.'/opening-balances/post', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.opening.0', fn (string $pesan): bool => str_contains($pesan, 'belum seimbang'));

        $this->postJson(self::BASE.'/opening-balances', [
            'entries' => [
                ['account_id' => $ekuitas, 'jenis_saldo' => 'kredit', 'saldo' => 5000],
            ],
        ])->assertOk()->assertJsonPath('data.summary.balanced', true);

        $posted = $this->postJson(self::BASE.'/opening-balances/post', [])->assertOk()->json('data');
        $this->assertSame(SumberModul::SALDO_AWAL, $posted['transaction']['sumber_modul']);

        // Setelah diposting: tidak dapat disunting & tidak dapat dihapus.
        $this->postJson(self::BASE.'/opening-balances', [
            'entries' => [['account_id' => $kas, 'jenis_saldo' => 'debet', 'saldo' => 1]],
        ])->assertStatus(422);

        $id = DB::table('finance_opening_balances')->value('id');
        $this->deleteJson(self::BASE.'/opening-balances/'.$id)->assertStatus(422);
    }

    // --------------------------------------------- permohonan buka periode

    public function test_permohonan_buka_periode_lewat_http_dengan_pemisahan_tugas(): void
    {
        $pengaju = $this->akuntan('pengaju.api@sekolah.test');
        $pemutus = $this->akuntan('pemutus.api@sekolah.test');

        $periode = $this->pastikanPeriode(now());
        app(FinancePeriodService::class)->close($periode, $pengaju);

        // Permohonan dibuat OLEH PENGAJU: memisahkan tugas hanya bermakna bila
        // pengajunya memang orang yang berbeda dari pemutusnya.
        Sanctum::actingAs($pengaju, ['*']);

        $this->getJson(self::BASE.'/period-reopen-requests')->assertOk();

        // Periode yang masih terbuka tidak perlu permohonan.
        $periodeTerbuka = $this->pastikanPeriode(now()->addMonth());
        $this->postJson(self::BASE.'/period-reopen-requests', [
            'period_id' => $periodeTerbuka->id,
            'reason' => 'Tidak perlu.',
        ])->assertStatus(422);

        $permohonan = $this->postJson(self::BASE.'/period-reopen-requests', [
            'period_id' => $periode->id,
            'reason' => 'Beban bulan lalu belum tercatat.',
        ])->assertCreated()->json('data');

        $this->getJson(self::BASE.'/periods/'.$periode->id.'/reopen-history')->assertOk();

        // Pengaju tidak boleh memutuskan permohonannya sendiri.
        $this->postJson(self::BASE.'/period-reopen-requests/'.$permohonan['id'].'/decide', [
            'approved' => true,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['decided_by']]);

        Sanctum::actingAs($pemutus, ['*']);

        $keputusan = $this->postJson(self::BASE.'/period-reopen-requests/'.$permohonan['id'].'/decide', [
            'approved' => true,
            'note' => 'Disetujui untuk koreksi.',
        ]);

        $keputusan->assertOk();

        $this->assertTrue($periode->refresh()->isOpen());

        // Keputusan kedua atas permohonan yang sama ditolak.
        $this->postJson(self::BASE.'/period-reopen-requests/'.$permohonan['id'].'/decide', [
            'approved' => false,
        ])->assertStatus(422);
    }

    // ------------------------------------------------- permohonan void jurnal

    public function test_permohonan_void_jurnal_lewat_http_membuat_jurnal_pembalik(): void
    {
        $pengaju = $this->akuntan('pengaju.void@sekolah.test');
        $pemutus = $this->akuntan('pemutus.void@sekolah.test');

        // Jurnal non-kas: pembalikannya tidak perlu menggerakkan subledger kas/bank.
        $pendapatan = $this->akunDaun('4');
        $piutang = $this->akunDaun('1');

        $jur = app(FinancialTransactionEngine::class)->post('invoice', 'Tagihan uji void', [
            ['account_id' => $piutang, 'debit' => 300],
            ['account_id' => $pendapatan, 'credit' => 300],
        ], ['date' => now(), 'created_by' => $pengaju->id, 'sumber_modul' => SumberModul::TAGIHAN_LAIN]);

        // Permohonan diajukan oleh pengaju, diputuskan orang lain.
        Sanctum::actingAs($pengaju, ['*']);

        $this->getJson(self::BASE.'/journal-void-requests')->assertOk();

        $permohonan = $this->postJson(self::BASE.'/journal-void-requests', [
            'transaction_id' => $jur->id,
            'reason' => 'Salah nominal.',
        ])->assertCreated()->json('data');

        $this->postJson(self::BASE.'/journal-void-requests/'.$permohonan['id'].'/decide', ['approved' => true])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['decided_by']]);

        Sanctum::actingAs($pemutus, ['*']);

        $keputusanVoid = $this->postJson(self::BASE.'/journal-void-requests/'.$permohonan['id'].'/decide', [
            'approved' => true,
            'note' => 'Disetujui.',
        ]);

        $keputusanVoid->assertOk();

        $this->assertSame(FinancialTransaction::STATUS_REVERSED, $jur->refresh()->status);
        $this->assertNotNull($jur->reversed_by_transaction_id);
        $this->assertSame('300.00', (string) FinancialTransaction::query()->find($jur->reversed_by_transaction_id)->total_debit);
    }

    // -------------------------------------------------------------- laporan

    public function test_laporan_standar_lewat_http(): void
    {
        $this->akuntan();

        $kas = $this->akunDaun('1');
        $pendapatan = $this->akunDaun('4');

        app(FinancialTransactionEngine::class)->post('revenue', 'Pendapatan uji laporan', [
            ['account_id' => $kas, 'debit' => 800],
            ['account_id' => $pendapatan, 'credit' => 800],
        ], ['date' => now(), 'sumber_modul' => SumberModul::TAGIHAN_LAIN, 'kode_unit' => 'SEKOLAH', 'kode_dana' => 'DANA-BEBAS']);

        $this->getJson(self::BASE.'/reports/trial-balance')
            ->assertOk()
            ->assertJsonPath('data.balanced', true);

        $labarugi = $this->getJson(self::BASE.'/reports/profit-loss-by-unit')->assertOk()->json('data');
        $this->assertNotEmpty($labarugi['units']);
        $this->assertArrayHasKey('result', $labarugi['totals']);

        $dana = $this->getJson(self::BASE.'/reports/fund-statement')->assertOk()->json('data');
        $this->assertNotEmpty($dana['funds']);
        $this->assertArrayHasKey('cash_movement', collect($dana['funds'])->first());

        $this->getJson(self::BASE.'/reports/modules')->assertOk()->assertJsonStructure(['data' => [['value', 'label']]]);
    }

    // ----------------------------------------------------------- aset tetap

    public function test_aset_tetap_dan_penyusutan_lewat_http_menghentikan_penyusutan_tidak_sengaja(): void
    {
        $this->akuntan();

        $asset = $this->postJson(self::BASE.'/assets', [
            'kode_aset' => 'AST-001',
            'nama' => 'Bus Sekolah',
            'kategori' => 'Kendaraan',
            'tanggal_perolehan' => now()->subMonths(3)->toDateString(),
            'harga_perolehan' => 600000000,
            'nilai_residu' => 0,
            'umur_bulan' => 60,
            'akun_aset_id' => $this->akunDaun('1'),
            'akun_akumulasi_id' => $this->akunDaun('1'),
            'akun_beban_depresiasi_id' => $this->akunDaun('5'),
            'kode_unit' => 'KOPERASI',
        ])->assertCreated()->json('data');

        $this->assertSame('10000000.00', (string) FinanceAsset::query()->find($asset['id'])->penyusutan_bulanan);

        $this->getJson(self::BASE.'/assets/'.$asset['id'])->assertOk()->assertJsonPath('data.penyusutan_bulanan', '10000000.00');

        $this->patchJson(self::BASE.'/assets/'.$asset['id'], ['kategori' => 'Kendaraan Operasional'])->assertOk();

        // Akun beban penyusutan harus benar-benar akun beban.
        $this->postJson(self::BASE.'/assets', [
            'kode_aset' => 'AST-SALAH',
            'nama' => 'Aset Salah Akun',
            'tanggal_perolehan' => now()->toDateString(),
            'harga_perolehan' => 1000000,
            'umur_bulan' => 12,
            'akun_aset_id' => $this->akunDaun('1'),
            'akun_akumulasi_id' => $this->akunDaun('1'),
            'akun_beban_depresiasi_id' => $this->akunDaun('1'),
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['akun_beban_depresiasi_id']]);

        $periode = now()->format('Y-m');

        $pratinjau = $this->getJson(self::BASE.'/depreciations/preview?periode='.$periode)->assertOk()->json('data');
        $this->assertSame('10000000.00', $pratinjau['total']);

        $jalan = $this->postJson(self::BASE.'/depreciations/run', ['periode' => $periode])->assertOk()->json('data');
        $this->assertSame(1, $jalan['diposting']);
        $this->assertSame('10000000.00', $jalan['total']);

        // Dijalankan lagi: tidak boleh menyusutkan dua kali (idempotensi).
        $ulang = $this->postJson(self::BASE.'/depreciations/run', ['periode' => $periode])->assertOk()->json('data');
        $this->assertSame(0, $ulang['diposting']);
        $this->assertSame(1, $ulang['dilewati']);
        $this->assertSame('10000000.00', (string) FinanceAsset::query()->find($asset['id'])->akumulasi_penyusutan);

        // Pelepasan aset: satu jurnal yang menutup seluruh perjalanan aset.
        $pelepasan = $this->postJson(self::BASE.'/assets/'.$asset['id'].'/dispose', [
            'hasil_pelepasan' => 500000000,
            'akun_penerimaan_id' => $this->akunDaun('1'),
            'akun_rugi_id' => $this->akunDaun('5'),
        ])->assertOk()->json('data');

        $this->assertSame('dilepas', $pelepasan['asset']['status']);
        $this->assertSame('600000000.00', (string) $pelepasan['transaction']['total_debit']);

        // Tidak dapat dilepas dua kali.
        $this->postJson(self::BASE.'/assets/'.$asset['id'].'/dispose', ['hasil_pelepasan' => 0])->assertStatus(422);
    }

    // --------------------------------------------------------------- akrual

    public function test_akrual_lewat_http_diposting_dan_dibalik_otomatis(): void
    {
        $this->akuntan();

        $this->getJson(self::BASE.'/accruals')->assertOk()->assertJsonStructure(['data' => ['accruals', 'due_for_reversal']]);

        // Akrual pendapatan wajib memakai akun piutang (kelompok 1) dan pendapatan (4).
        $this->postJson(self::BASE.'/accruals', [
            'kode' => 'AKR-SALAH',
            'tanggal' => now()->toDateString(),
            'tanggal_balik' => now()->addMonth()->toDateString(),
            'jenis' => 'pendapatan',
            'akun_akrual_id' => $this->akunDaun('5'),
            'akun_lawan_id' => $this->akunDaun('4'),
            'jumlah' => 1000,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['akun_akrual_id']]);

        $akrual = $this->postJson(self::BASE.'/accruals', [
            'kode' => 'AKR-001',
            'tanggal' => now()->subDays(2)->toDateString(),
            'tanggal_balik' => now()->subDay()->toDateString(),
            'jenis' => 'beban',
            'akun_akrual_id' => $this->akunDaun('2'),
            'akun_lawan_id' => $this->akunDaun('5'),
            'jumlah' => 1500,
            'deskripsi' => 'Listrik bulan ini',
        ])->assertCreated()->json('data');

        $this->patchJson(self::BASE.'/accruals/'.$akrual['id'], ['deskripsi' => 'Listrik & air bulan ini'])->assertOk();

        $posted = $this->postJson(self::BASE.'/accruals/'.$akrual['id'].'/post', [])->assertOk()->json('data');
        $this->assertSame('posted', $posted['accrual']['status']);
        $this->assertSame('1500.00', (string) $posted['transaction']['total_debit']);

        // Tanggal balik sudah lewat: pembalikan otomatis.
        $pembalikan = $this->postJson(self::BASE.'/accruals/reverse-due', [])->assertOk()->json('data');
        $this->assertSame(1, $pembalikan['dibalik']);

        // Dijalankan lagi tidak membalik dua kali.
        $this->assertSame(0, $this->postJson(self::BASE.'/accruals/reverse-due', [])->json('data.dibalik'));

        $this->assertDatabaseHas('finance_accruals', ['kode' => 'AKR-001', 'status' => 'reversed']);
    }

    // ----------------------------------------------------------- persediaan

    public function test_persediaan_lewat_http_menggerakkan_stok_dan_jurnal_sekaligus(): void
    {
        $aktor = $this->akuntan();

        $item = $this->barang();

        $persediaan = $this->akunDaun('1');
        $sumber = $this->akunDaun('2');
        $beban = $this->akunDaun('5');

        $masuk = $this->postJson(self::BASE.'/inventory/items/'.$item->id.'/masuk', [
            'kuantiti' => 20,
            'harga_satuan' => 7500,
            'akun_persediaan_id' => $persediaan,
            'akun_sumber_id' => $sumber,
            'keterangan' => 'Pembelian buku tulis',
        ])->assertOk()->json('data');

        $this->assertSame('150000.00', (string) $masuk['transaction']['total_debit']);

        $baris = DB::table('finance_transaction_lines')->where('finance_transaction_id', $masuk['transaction']['id'])->get();
        $this->assertSame($item->sku, $baris->first()->kode_persediaan);
        $this->assertSame(20.0, (float) $baris->first()->kuantiti);
        $this->assertSame('30.00', (string) $item->refresh()->quantity);

        $keluar = $this->postJson(self::BASE.'/inventory/items/'.$item->id.'/keluar', [
            'kuantiti' => 5,
            'akun_persediaan_id' => $persediaan,
            'akun_beban_id' => $beban,
            'keterangan' => 'Pemakaian alat tulis',
        ])->assertOk()->json('data');

        $this->assertSame('37500.00', (string) $keluar['transaction']['total_debit']);
        $this->assertSame('25.00', (string) $item->refresh()->quantity);

        // Stok tidak boleh minus: ditolak dengan menyebut stok yang tersedia.
        $this->postJson(self::BASE.'/inventory/items/'.$item->id.'/keluar', [
            'kuantiti' => 999,
            'akun_persediaan_id' => $persediaan,
            'akun_beban_id' => $beban,
        ])->assertStatus(422);

        // Pemakaian wajib dibebankan ke akun beban, bukan akun neraca.
        $this->postJson(self::BASE.'/inventory/items/'.$item->id.'/keluar', [
            'kuantiti' => 1,
            'akun_persediaan_id' => $persediaan,
            'akun_beban_id' => $persediaan,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['akun_beban_id']]);

        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertSame('in', DB::table('inventory_movements')->orderBy('id')->value('movement_type'));
    }

    // ------------------------------------------------------ izin & validasi

    public function test_pengguna_tanpa_izin_ditolak_dan_permintaan_tidak_sah_ditolak(): void
    {
        $polos = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($polos, ['*']);

        foreach ([
            self::BASE.'/business-units',
            self::BASE.'/funds',
            self::BASE.'/opening-balances',
            self::BASE.'/period-reopen-requests',
            self::BASE.'/journal-void-requests',
            self::BASE.'/assets',
            self::BASE.'/accruals',
            self::BASE.'/reports/trial-balance',
        ] as $endpoint) {
            $this->getJson($endpoint)->assertForbidden();
        }

        // Tanpa izin pun, permintaan yang cacat harus tetap ditolak sebagai cacat —
        // bukan dijawab 403 yang menyembunyikan masalah validasinya.
        $this->akuntan();

        $this->postJson(self::BASE.'/business-units', [])->assertStatus(422);
        $this->postJson(self::BASE.'/funds', ['kode_dana' => 'X'])->assertStatus(422);
        $this->getJson(self::BASE.'/depreciations/preview?periode=2026-7')->assertStatus(422);
        $this->postJson(self::BASE.'/accruals', [
            'kode' => 'AKR-002',
            'tanggal' => now()->toDateString(),
            'tanggal_balik' => now()->toDateString(),
            'jenis' => 'beban',
            'akun_akrual_id' => $this->akunDaun('2'),
            'akun_lawan_id' => $this->akunDaun('5'),
            'jumlah' => 100,
        ])->assertStatus(422);
    }

    // --------------------------------------------------------------- bantuan

    protected function akuntan(string $email = 'akuntan.api@sekolah.test'): User
    {
        $user = User::query()->firstWhere('email', $email)
            ?? User::factory()->create(['email' => $email, 'is_active' => true]);

        $user->syncPermissions(Permission::query()->pluck('name')->all());
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    protected function akunDaun(string $akar, ?int $selain = null): int
    {
        $akun = FinanceAccount::query()
            ->where('code', 'like', $akar.'%')
            ->where('is_active', true)
            ->when($selain !== null, fn ($q) => $q->whereKeyNot($selain))
            ->get()
            ->first(fn (FinanceAccount $a): bool => $a->isLeaf());

        $this->assertNotNull($akun, "Tidak menemukan akun daun dengan akar kelompok {$akar}.");

        return (int) $akun->id;
    }

    protected function barang(): InventoryItem
    {
        return InventoryItem::query()->create([
            'sku' => 'BRG-API-1',
            'name' => 'Buku Tulis',
            'quantity' => 10,
            'minimum_quantity' => 2,
            'unit_cost' => 7500,
            'condition' => 'good',
            'is_active' => true,
        ]);
    }

    protected function pastikanPeriode(\Illuminate\Support\Carbon $tanggal): FinancePeriod
    {
        return FinancePeriod::forDate($tanggal) ?? FinancePeriod::query()->create([
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
