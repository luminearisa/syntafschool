<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceCostCenter;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\FinanceTransfer;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Laporan keuangan (§67, §68) lewat HTTP — angka harus benar, bukan sekadar endpoint ada.
 *
 * Skenario buku besar yang dibangun:
 *   1. Terbitkan invoice 850k  → Dr Piutang(1300) / Cr Pendapatan SPP(4100) 350k + Asrama(4200) 500k
 *   2. Bayar tunai 850k         → Dr Kas(1100) / Cr Piutang(1300)
 *   3. Bayar beban utilitas 200k → Dr Beban(5200) / Cr Kas(1100)
 *
 * Invarian yang diuji: Neraca Lajur total debit == total kredit (§47); Laba Rugi
 * netto = pendapatan − beban; Neraca menutup (Aset == Kewajiban + Ekuitas, laba
 * berjalan dilipat ke ekuitas); Buku Besar menghitung saldo berjalan PER akun
 * pada sisi normalnya dan membawa saldo awal saat `from` dipakai (§35–§37);
 * Laporan Pendapatan diambil dari jurnal, bukan tabel invoice (§42); Arus Kas
 * Opening + Masuk − Keluar = Closing dengan transfer sebagai mutasi internal
 * (§45, §29); ekspor CSV memakai satu sumber baris yang sama (BOM +
 * content-type); guard permission.
 */
class FinanceReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceConfigSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $email = str_replace('-', '.', $role).'@schoolerp.test';

        $user = User::query()->firstWhere('email', $email)
            ?? User::factory()->create(['email' => $email, 'is_active' => true]);

        $user->syncRoles([$role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function account(string $code): int
    {
        return (int) FinanceAccount::query()->where('code', $code)->value('id');
    }

    private function cash(string $code = 'KAS-SEKOLAH'): FinanceCashAccount
    {
        return FinanceCashAccount::query()->where('code', $code)->firstOrFail();
    }

    private function costCenter(string $code = 'CC-SEKOLAH'): FinanceCostCenter
    {
        return FinanceCostCenter::query()->where('code', $code)->firstOrFail();
    }

    private function student(string $nis): Student
    {
        return Student::query()->firstOrCreate(['nis' => $nis], ['full_name' => 'Santri '.$nis, 'is_active' => true]);
    }

    private function feeType(string $code): FinanceFeeType
    {
        return FinanceFeeType::query()->where('code', $code)->firstOrFail();
    }

    private function package(string $code): FinanceFeePackage
    {
        $package = FinanceFeePackage::query()->firstOrCreate(
            ['code' => $code],
            ['name' => 'Paket '.$code, 'boarding_type' => 'all', 'is_active' => true],
        );

        if ($package->items()->count() === 0) {
            $package->items()->createMany([
                ['fee_type_id' => $this->feeType('SPP')->id, 'amount' => 350000, 'frequency' => 'monthly', 'is_active' => true],
                ['fee_type_id' => $this->feeType('UANG_ASRAMA')->id, 'amount' => 500000, 'frequency' => 'monthly', 'is_active' => true],
            ]);
        }

        return $package;
    }

    /**
     * Bangun buku besar contoh (invoice → bayar → beban) sebagai finance-admin.
     * Mengembalikan invoice yang diterbitkan.
     */
    private function seedLedger(): FinanceInvoice
    {
        $this->actingAsRole('finance-admin');
        $this->cash()->update(['current_balance' => 10000000]); // cukup untuk pengeluaran tunai

        $package = $this->package('PKT-REP');
        $student = $this->student('REP001');

        $billingId = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id,
            'period_key' => '2026-09',
            'student_ids' => [$student->id],
            'due_date' => '2026-09-30',
        ])->assertOk()->json('data.billings.0');

        $invoice = FinanceInvoice::find(
            $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated()->json('data.id')
        );

        // Bayar tunai penuh.
        $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
        ])->assertCreated();

        // Pengeluaran beban utilitas 200k: ajukan → setujui → bayar.
        $this->actingAsRole('kepala-unit');
        $reqId = (int) $this->postJson('/api/v1/finance/expense-requests', [
            'cost_center_id' => $this->costCenter()->id,
            'account_id' => $this->account('5200'),
            'amount' => 200000,
            'description' => 'Listrik asrama',
            'expense_date' => '2026-09-10',
            'submit' => true,
        ])->assertCreated()->json('data.id');

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/expense-requests/{$reqId}/approve")->assertOk();

        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/expense-requests/{$reqId}/pay", ['cash_account_id' => $this->cash()->id])
            ->assertCreated();

        return $invoice;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $kind, array $query = []): array
    {
        return $this->getJson('/api/v1/finance/reports/'.$kind.($query ? '?'.http_build_query($query) : ''))
            ->assertOk()
            ->json('data.rows');
    }

    private function totalRow(array $rows, string $name): array
    {
        foreach ($rows as $row) {
            if (($row['account_name'] ?? null) === $name) {
                return $row;
            }
        }

        $this->fail("Baris '{$name}' tidak ditemukan di laporan.");
    }

    // =================================================================

    public function test_daftar_jenis_laporan_lengkap(): void
    {
        $this->actingAsRole('finance-admin');
        $kinds = $this->getJson('/api/v1/finance/reports/kinds')->assertOk()->json('data');

        $keys = collect($kinds)->pluck('kind')->all();
        foreach (['receivables', 'aging', 'payments', 'expenses', 'cash_bank', 'budget',
            'ledger', 'trial_balance', 'revenue', 'income_statement', 'balance_sheet', 'cash_flow'] as $expected) {
            $this->assertContains($expected, $keys);
        }
    }

    public function test_jenis_laporan_tidak_dikenal_404(): void
    {
        $this->actingAsRole('finance-admin');
        $this->getJson('/api/v1/finance/reports/ngawur')->assertStatus(404);
    }

    public function test_neraca_lajur_seimbang_debit_sama_dengan_kredit(): void
    {
        $this->seedLedger();

        $rows = $this->rows('trial_balance');
        $total = $this->totalRow($rows, 'TOTAL');

        $this->assertEquals(850000.00, (float) $total['debit']);
        $this->assertEquals(850000.00, (float) $total['credit']);
        $this->assertEquals((float) $total['debit'], (float) $total['credit'], 'Trial balance harus seimbang (§47)');
    }

    public function test_laba_rugi_menghitung_netto_pendapatan_dikurangi_beban(): void
    {
        $this->seedLedger();

        $rows = $this->rows('income_statement');

        $this->assertEquals(850000.00, (float) $this->totalRow($rows, 'Total Pendapatan')['amount']);
        $this->assertEquals(200000.00, (float) $this->totalRow($rows, 'Total Beban')['amount']);
        $this->assertEquals(650000.00, (float) $this->totalRow($rows, 'Laba (Rugi) Bersih')['amount']);
    }

    public function test_neraca_menutup_aset_sama_dengan_kewajiban_plus_ekuitas(): void
    {
        $this->seedLedger();

        $rows = $this->rows('balance_sheet');

        $aset = (float) $this->totalRow($rows, 'TOTAL ASET')['amount'];
        $kewajibanEkuitas = (float) $this->totalRow($rows, 'TOTAL KEWAJIBAN + EKUITAS')['amount'];

        // Kas 850k − beban 200k = 650k; piutang nol setelah dibayar.
        $this->assertEquals(650000.00, $aset);
        $this->assertEquals($aset, $kewajibanEkuitas, 'Neraca harus menutup (Aset = Kewajiban + Ekuitas)');
    }

    public function test_buku_besar_saldo_berjalan_akumulatif(): void
    {
        $this->seedLedger();

        $rows = $this->rows('ledger', ['account_id' => $this->account('1100')]);

        $this->assertNotEmpty($rows);
        $last = end($rows);
        // Kas: +850k (pembayaran) − 200k (beban) = 650k.
        $this->assertEquals(650000.00, (float) $last['running_balance']);
    }

    public function test_ekspor_csv_memakai_sumber_baris_yang_sama(): void
    {
        $this->seedLedger();

        $this->actingAsRole('finance-admin');
        $resp = $this->get('/api/v1/finance/reports/trial_balance/export')->assertOk();

        $resp->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $body = $resp->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'CSV harus diawali BOM UTF-8');
        $this->assertStringContainsString('Kode Akun', $body);
        $this->assertStringContainsString('TOTAL', $body);
    }

    public function test_kasir_boleh_melihat_tapi_tidak_boleh_mengekspor(): void
    {
        $this->seedLedger();

        $this->actingAsRole('kasir'); // report.view, tanpa report.export
        $this->getJson('/api/v1/finance/reports/trial_balance')->assertOk();
        $this->getJson('/api/v1/finance/reports/trial_balance/export')->assertStatus(403);
    }

    public function test_siswa_tidak_boleh_melihat_laporan_keuangan(): void
    {
        $this->actingAsRole('siswa');
        $this->getJson('/api/v1/finance/reports/kinds')->assertStatus(403);
    }

    // ==================== §35–§37: buku besar per saldo normal ====================

    public function test_buku_besar_pendapatan_berjalan_di_sisi_normal_kredit(): void
    {
        $this->seedLedger();

        $rows = $this->rows('ledger', ['account_id' => $this->account('4100')]);

        $this->assertNotEmpty($rows);
        // Akun bergaya kredit bertambah di sisi kredit: saldonya +350k, bukan
        // −350k seperti bila debit−credit dipakai mentah-mentah (§37).
        $this->assertEquals(350000.00, (float) end($rows)['running_balance']);
    }

    public function test_buku_besar_tidak_menjumlahkan_saldo_lintas_akun(): void
    {
        $this->seedLedger();

        $last = [];

        foreach ($this->rows('ledger') as $row) {
            $last[$row['account_code']] = (float) $row['running_balance'];
        }

        $this->assertEquals(350000.0, $last['4100']);
        $this->assertEquals(500000.0, $last['4200']);
        $this->assertEquals(200000.0, $last['5200']);
        // Piutang lunas → 0; kas hanya mencatat neto jurnal (10jt saldo subledger
        // yang diisi manual tidak ada di buku besar).
        $this->assertEquals(0.0, $last['1300']);
        $this->assertEquals(650000.0, $last['1100']);
    }

    public function test_buku_besar_membawa_saldo_awal_sebelum_filter_dari(): void
    {
        $this->seedLedger();

        // Beban tunai 10 Sep (Cr Kas 200k) terjadi sebelum rentang tanya;
        // pembayaran SPP 26 Sep (Dr Kas 850k) di dalamnya. Saldo awal kas
        // karenanya -200k dan saldo berjalan penutup 650k (§35).
        $rows = $this->rows('ledger', ['account_id' => $this->account('1100'), 'from' => '2026-09-11']);

        $this->assertNotEmpty($rows);
        $this->assertEquals(-200000.0, (float) $rows[0]['opening_balance']);
        $this->assertEquals(650000.0, (float) end($rows)['running_balance']);
    }

    // ==================== §42: laporan pendapatan ====================

    public function test_laporan_pendapatan_per_akun_dari_buku_besar(): void
    {
        $this->seedLedger();

        $rows = $this->rows('revenue');
        $byCode = collect($rows)->keyBy('account_code');

        $this->assertEquals(350000.0, (float) $byCode['4100']['amount']);
        $this->assertEquals(350000.0, (float) $byCode['4100']['recognized']);
        $this->assertEquals(0.0, (float) $byCode['4100']['reversal']);
        $this->assertEquals(500000.0, (float) $byCode['4200']['amount']);
        $this->assertEquals(850000.0, (float) $this->totalRow($rows, 'TOTAL PENDAPATAN')['amount']);
        // Kontribusi dua akun pendapatan menutup 100%.
        $this->assertEqualsWithDelta(100.0, (float) $byCode['4100']['share'] + (float) $byCode['4200']['share'], 0.01);
    }

    public function test_laporan_pendapatan_hanya_per_periode(): void
    {
        $this->seedLedger();

        // Jurnal seluruhnya terjadi September; Oktober harus kosong (§35).
        $rows = $this->rows('revenue', ['from' => '2026-10-01', 'to' => '2026-10-31']);

        $this->assertCount(1, $rows);
        $this->assertEquals(0.0, (float) $this->totalRow($rows, 'TOTAL PENDAPATAN')['amount']);
    }

    // ==================== §45: arus kas ====================

    public function test_arus_kas_operasi_ouverture_sampai_saldo_akhir(): void
    {
        $this->seedLedger();

        $rows = $this->rows('cash_flow');
        $descriptions = collect($rows)->pluck('description')->all();

        $this->assertStringContainsString('Saldo awal pembukuan', $descriptions[0]);
        $this->assertEquals(0.0, (float) $rows[0]['amount']);

        $byDescription = collect($rows)->keyBy('description');
        $this->assertEquals(850000.0, (float) $byDescription['Pembayaran (masuk)']['amount']);
        $this->assertEquals(-200000.0, (float) $byDescription['Beban (keluar)']['amount']);
        $this->assertEquals(650000.0, (float) $byDescription['Subtotal Arus Kas Aktivitas Operasi']['amount']);
        $this->assertEquals(650000.0, (float) $byDescription['Arus kas bersih (masuk − keluar)']['amount']);
        $this->assertEquals(650000.0, (float) $byDescription['Saldo Akhir Kas & Bank']['amount']);
    }

    public function test_arus_kas_memakai_saldo_awal_dari_sebelum_filter_dari(): void
    {
        $this->seedLedger();

        // Seluruh jurnal terjadi sebelum Oktober → tak ada mutasi dalam periode,
        // saldo awal = saldo akhir = 650k (§45: Opening + In − Out = Closing).
        $rows = $this->rows('cash_flow', ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $byDescription = collect($rows)->keyBy('description');

        $this->assertEquals(650000.0, (float) $byDescription['Saldo sebelum 2026-10-01']['amount']);
        $this->assertEquals(0.0, (float) $byDescription['Arus kas bersih (masuk − keluar)']['amount']);
        $this->assertEquals(650000.0, (float) $byDescription['Saldo Akhir Kas & Bank']['amount']);
    }

    public function test_transfer_kas_ke_bank_bukan_arus_kas(): void
    {
        $this->seedLedger();

        $this->actingAsRole('finance-officer');
        $transferId = (int) $this->postJson('/api/v1/finance/transfers', [
            'source_type' => FinanceTransfer::SIDE_CASH,
            'source_id' => $this->cash()->id,
            'destination_type' => FinanceTransfer::SIDE_BANK,
            'destination_id' => $this->bank()->id,
            'amount' => 100000,
            'transfer_date' => '2026-09-15',
            'reason' => 'Setor kas ke bank',
            'submit' => true,
        ])->assertCreated()->json('data.id');

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/transfers/{$transferId}/approve")->assertOk();
        $this->postJson("/api/v1/finance/transfers/{$transferId}/complete")->assertOk();

        $rows = $this->rows('cash_flow');
        $byDescription = collect($rows)->keyBy('description');

        // Mutasi internal tampak dua-duanya tapi netral, dan tidak ikut
        // menghitung arus kas bersih (§29).
        $this->assertEquals(-100000.0, (float) $byDescription['Mutasi antar rekening kas/bank (keluar)']['amount']);
        $this->assertEquals(100000.0, (float) $byDescription['Mutasi antar rekening kas/bank (masuk)']['amount']);
        $this->assertEquals(0.0, (float) $byDescription['Subtotal Mutasi Internal Kas / Bank']['amount']);
        $this->assertEquals(650000.0, (float) $byDescription['Arus kas bersih (masuk − keluar)']['amount']);
    }

    private function bank(): FinanceBankAccount
    {
        return FinanceBankAccount::query()->where('code', 'BANK-OPS')->firstOrFail();
    }
}
