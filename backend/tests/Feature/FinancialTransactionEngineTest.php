<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinancePeriod;
use App\Models\FinancialTransaction;
use App\Services\Finance\FinancialTransactionEngine;
use Database\Seeders\FinanceConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Mesin buku besar double-entry (§46, §47, §49, §51, §61) — diuji langsung
 * sebagai service karena inilah fondasi seluruh pembukuan finance. Yang
 * diverifikasi: keseimbangan debit=credit, penolakan periode closed, hanya akun
 * daun yang boleh diposting, pergerakan saldo subledger kas/bank, penjagaan saldo
 * negatif, dan reversal yang tidak mengubah jurnal asli.
 */
class FinancialTransactionEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinanceConfigSeeder::class);
    }

    private function engine(): FinancialTransactionEngine
    {
        return app(FinancialTransactionEngine::class);
    }

    private function account(string $code): FinanceAccount
    {
        return FinanceAccount::query()->where('code', $code)->firstOrFail();
    }

    private function cash(string $code = 'KAS-SEKOLAH'): FinanceCashAccount
    {
        return FinanceCashAccount::query()->where('code', $code)->firstOrFail();
    }

    public function test_posting_seimbang_menyimpan_jurnal_dan_baris(): void
    {
        $kas = $this->cash();
        $revenue = $this->account('4100');

        $tx = $this->engine()->cashReceipt($kas, $revenue->id, 150000, 'Pembayaran SPP', [
            'balances' => [],
        ]);

        $this->assertSame(FinancialTransaction::STATUS_POSTED, $tx->status);
        $this->assertEquals(150000.00, (float) $tx->total_debit);
        $this->assertEquals(150000.00, (float) $tx->total_credit);
        $this->assertTrue($tx->isBalanced());
        $this->assertCount(2, $tx->lines);
        $this->assertNotNull($tx->period_id);
        $this->assertStringStartsWith('JRN-', $tx->transaction_number);

        // Saldo subledger kas konkret ikut bergerak.
        $this->assertEquals(150000.00, (float) $kas->fresh()->current_balance);
    }

    public function test_jurnal_tidak_seimbang_ditolak(): void
    {
        $this->expectException(ValidationException::class);

        $this->engine()->post(FinancialTransaction::TYPE_PAYMENT, 'Rusak', [
            $this->engine()->debit($this->account('1100')->id, 100000),
            $this->engine()->credit($this->account('4100')->id, 90000),
        ]);
    }

    public function test_periode_closed_menolak_posting(): void
    {
        // Tutup periode bulan berjalan, coba posting bertanggal di dalamnya.
        $now = Carbon::now();
        $period = FinancePeriod::forDate($now);
        $this->assertNotNull($period);
        $period->update(['status' => FinancePeriod::STATUS_CLOSED]);

        $this->expectException(ValidationException::class);

        $this->engine()->cashReceipt($this->cash(), $this->account('4100')->id, 50000, 'Ditolak', [
            'date' => $now,
        ]);
    }

    public function test_akun_induk_tidak_boleh_diposting(): void
    {
        $induk = $this->account('1000'); // punya anak → bukan daun
        $this->assertFalse($induk->isLeaf());

        $this->expectException(ValidationException::class);

        $this->engine()->post(FinancialTransaction::TYPE_PAYMENT, 'Induk', [
            $this->engine()->debit($induk->id, 10000),
            $this->engine()->credit($this->account('4100')->id, 10000),
        ]);
    }

    public function test_pengeluaran_mengurangi_saldo_dan_menolak_bila_minus(): void
    {
        $kas = $this->cash();
        $beban = $this->account('5200');

        // Setor dulu 100.000 agar ada saldo.
        $this->engine()->cashReceipt($kas, $this->account('4100')->id, 100000, 'Setor');
        $this->assertEquals(100000.00, (float) $kas->fresh()->current_balance);

        // Keluar 40.000 → sisa 60.000.
        $this->engine()->cashDisbursement($kas, $beban->id, 40000, 'Bayar listrik');
        $this->assertEquals(60000.00, (float) $kas->fresh()->current_balance);

        // Keluar melebihi saldo → ditolak, saldo tidak berubah.
        try {
            $this->engine()->cashDisbursement($kas, $beban->id, 500000, 'Kelebihan');
            $this->fail('Seharusnya menolak saldo negatif.');
        } catch (ValidationException) {
            $this->assertEquals(60000.00, (float) $kas->fresh()->current_balance);
        }
    }

    public function test_transfer_memindahkan_saldo_antar_kas_tanpa_pendapatan(): void
    {
        $sumber = $this->cash('KAS-SEKOLAH');
        $tujuan = $this->cash('KAS-PESANTREN');

        $this->engine()->cashReceipt($sumber, $this->account('4100')->id, 200000, 'Setor awal');
        $this->engine()->transfer($sumber, $tujuan, 75000, 'Mutasi ke pesantren');

        $this->assertEquals(125000.00, (float) $sumber->fresh()->current_balance);
        $this->assertEquals(75000.00, (float) $tujuan->fresh()->current_balance);
    }

    public function test_pembukaan_saldo_mendebet_kas_dan_mengkredit_ekuitas(): void
    {
        $kas = $this->cash();
        $ekuitas = $this->account('3100');

        $tx = $this->engine()->openingBalance($kas, $ekuitas->id, 500000, 'Saldo awal kas');

        $this->assertSame(FinancialTransaction::TYPE_OPENING, $tx->type);
        $this->assertEquals(500000.00, (float) $kas->fresh()->current_balance);
        $this->assertEquals(500000.00, (float) $tx->total_debit);
    }

    public function test_reversal_membuat_lawan_tanpa_mengubah_jurnal_asli(): void
    {
        $kas = $this->cash();
        $revenue = $this->account('4100');

        $original = $this->engine()->cashReceipt($kas, $revenue->id, 100000, 'Pembayaran');
        $originalNumber = $original->transaction_number;
        $this->assertEquals(100000.00, (float) $kas->fresh()->current_balance);

        $counter = $this->engine()->reverse($original, 'Salah catat', null, [
            ['account' => $kas, 'delta' => -100000],
        ]);

        // Jurnal asli ditandai reversed & ditautkan, nominalnya tetap.
        $fresh = $original->fresh();
        $this->assertSame(FinancialTransaction::STATUS_REVERSED, $fresh->status);
        $this->assertSame($counter->id, $fresh->reversed_by_transaction_id);
        $this->assertEquals(100000.00, (float) $fresh->total_debit);
        $this->assertSame($originalNumber, $fresh->transaction_number);

        // Jurnal pembalik seimbang dan menukar sisi.
        $this->assertTrue($counter->isBalanced());
        $this->assertEquals(100000.00, (float) $counter->total_debit);

        // Saldo subledger kembali nol.
        $this->assertEquals(0.00, (float) $kas->fresh()->current_balance);

        // Pembalikan ganda ditolak.
        $this->expectException(ValidationException::class);
        $this->engine()->reverse($original->fresh(), 'Coba lagi');
    }

    public function test_akrual_pendapatan_tidak_menyentuh_kas(): void
    {
        $kas = $this->cash();
        $piutang = $this->account('1300');
        $revenue = $this->account('4100');

        $this->engine()->accrueRevenue($piutang->id, $revenue->id, 250000, 'Terbit invoice');

        $this->assertEquals(0.00, (float) $kas->fresh()->current_balance);
        $this->assertSame(1, FinancialTransaction::query()->count());
    }
}
