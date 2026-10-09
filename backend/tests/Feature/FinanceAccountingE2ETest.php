<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceCostCenter;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\FinancePeriod;
use App\Models\FinanceReceipt;
use App\Models\FinanceRefund;
use App\Models\FinanceVendor;
use App\Models\FinancialTransaction;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Bukti end-to-end pembukuan Phase 3 (§48–§50). Bukan sekadar "tabel jurnal ada":
 * setiap kasus menjalankan rantai bisnis lengkap lewat HTTP —
 *
 *   billing → invoice → payment → (verifikasi) → receipt
 *     → financial transaction → journal lines → general ledger
 *     → AR / AP → laporan (pendapatan, laba rugi, neraca, neraca lajur, arus kas)
 *
 * dan menguji invarian yang membuat pembukuan itu sah: total debit == total
 * kredit di setiap titik; pendapatan 650.000 tetap 650.000 setelah dibayar
 * (tidak dicatat dua kali, §7); pembayaran menunggu verifikasi tidak memposting
 * apa pun (§21); periode closed menolak posting dan tidak meninggalkan jejak
 * (§49); refund/pembalikan tidak menghapus dokumen asli (§28, §51); write-off
 * tidak menyentuh pendapatan (§33); saldo kas/bank konkret selalu sama dengan
 * buku besarnya (§27, §61); dan endpoint penelusuran (§38) menunjuk rantai yang
 * sama.
 *
 * Basis cash diuji sebagai kebijakan, bukan kode terpisah (§9): dokumen yang
 * sama, jurnalnya berbeda tempat.
 */
class FinanceAccountingE2ETest extends TestCase
{
    use RefreshDatabase;

    /** Satu paket SPP 350.000 + asrama 300.000 = 650.000 (angka DoD §48). */
    private const SPP = 350000;

    private const ASRAMA = 300000;

    private const TAGIHAN = 650000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceConfigSeeder::class);
    }

    // ==================== Helper ====================

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

    private function code(int $accountId): string
    {
        return (string) FinanceAccount::query()->whereKey($accountId)->value('code');
    }

    private function cash(): FinanceCashAccount
    {
        return FinanceCashAccount::query()->where('code', 'KAS-SEKOLAH')->firstOrFail();
    }

    private function bank(): FinanceBankAccount
    {
        return FinanceBankAccount::query()->where('code', 'BANK-OPS')->firstOrFail();
    }

    private function student(string $nis): Student
    {
        return Student::query()->firstOrCreate(['nis' => $nis], ['full_name' => 'Santri '.$nis, 'is_active' => true]);
    }

    private function feeType(string $code): FinanceFeeType
    {
        return FinanceFeeType::query()->where('code', $code)->firstOrFail();
    }

    /** Isi kas lewat jurnal saldo awal — satu-satunya jalan sah (§27). */
    private function openCash(float $amount): void
    {
        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/cash-accounts/{$this->cash()->id}/opening-balance", [
            'amount' => $amount,
            'note' => 'Saldo awal kas',
        ])->assertOk();
    }

    private function package(string $code): FinanceFeePackage
    {
        $package = FinanceFeePackage::query()->firstOrCreate(
            ['code' => $code],
            ['name' => 'Paket '.$code, 'boarding_type' => 'all', 'is_active' => true],
        );

        if ($package->items()->count() === 0) {
            $package->items()->createMany([
                ['fee_type_id' => $this->feeType('SPP')->id, 'amount' => self::SPP, 'frequency' => 'monthly', 'is_active' => true],
                ['fee_type_id' => $this->feeType('UANG_ASRAMA')->id, 'amount' => self::ASRAMA, 'frequency' => 'monthly', 'is_active' => true],
            ]);
        }

        return $package;
    }

    /** Billing paket → invoice (jurnal akrual terbit di dalamnya). */
    private function issueInvoice(string $nis, string $packageCode): FinanceInvoice
    {
        $this->actingAsRole('finance-admin');

        $billingId = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $this->package($packageCode)->id,
            'period_key' => '2026-09',
            'student_ids' => [$this->student($nis)->id],
            'due_date' => '2026-09-28',
        ])->assertOk()->json('data.billings.0');

        return FinanceInvoice::find(
            $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated()->json('data.id')
        );
    }

    /** Tagihan lain (satu jenis tagihan, nominal bebas) → invoice. */
    private function issueOtherBilling(string $nis, string $feeCode, float $amount, string $reference): FinanceInvoice
    {
        $this->actingAsRole('finance-admin');

        $billingId = $this->postJson('/api/v1/finance/billings/other/generate', [
            'fee_type_id' => $this->feeType($feeCode)->id,
            'period_key' => '2026-09',
            'amount' => $amount,
            'student_ids' => [$this->student($nis)->id],
            'due_date' => '2026-09-28',
            'reference' => $reference,
        ])->assertOk()->json('data.billings.0');

        return FinanceInvoice::find(
            $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated()->json('data.id')
        );
    }

    /**
     * Pembayaran tunai atas satu invoice.
     *
     * @return list<int> id payment yang terbentuk
     */
    private function payInvoice(FinanceInvoice $invoice, ?float $amount = null, string $method = 'cash'): int
    {
        $this->actingAsRole('finance-admin');

        return (int) $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => $amount ?? (float) $invoice->remaining_amount,
            'method' => $method,
            'cash_account_id' => $method === 'cash' ? $this->cash()->id : null,
            'bank_account_id' => $method === 'cash' ? null : $this->bank()->id,
        ])->assertCreated()->json('data.id');
    }

    private function journalFor(FinanceInvoice $invoice, string $type): FinancialTransaction
    {
        return FinancialTransaction::query()
            ->with('lines.account')
            ->where('reference_type', FinanceInvoice::class)
            ->where('reference_id', $invoice->id)
            ->where('type', $type)
            ->where('status', FinancialTransaction::STATUS_POSTED)
            ->firstOrFail();
    }

    private function journalCount(): int
    {
        return FinancialTransaction::query()->count();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function reportRows(string $kind, array $query = []): array
    {
        $this->actingAsRole('finance-admin');

        return $this->getJson('/api/v1/finance/reports/'.$kind.($query ? '?'.http_build_query($query) : ''))
            ->assertOk()
            ->json('data.rows');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function reportRow(string $kind, string $accountName, array $query = []): array
    {
        foreach ($this->reportRows($kind, $query) as $row) {
            if (($row['account_name'] ?? null) === $accountName) {
                return $row;
            }
        }

        $this->fail("Baris '{$accountName}' tidak ada di laporan {$kind}.");
    }

    /** Total pendapatan yang sudah diakui di buku besar (§42). */
    private function recognizedRevenue(): float
    {
        return (float) $this->reportRow('revenue', 'TOTAL PENDAPATAN')['amount'];
    }

    /** Neraca lajur + neraca harus menutup: ini "buku besar sehat" version singkat. */
    private function assertBooksClose(string $context = ''): void
    {
        $trial = $this->reportRow('trial_balance', 'TOTAL');
        $this->assertEquals(
            (float) $trial['debit'],
            (float) $trial['credit'],
            "Neraca lajur tidak seimbang {$context}: {$trial['debit']} vs {$trial['credit']}"
        );

        $sheet = collect($this->reportRows('balance_sheet'))->keyBy('account_name');
        $this->assertEquals(
            (float) $sheet['TOTAL ASET']['amount'],
            (float) $sheet['TOTAL KEWAJIBAN + EKUITAS']['amount'],
            "Neraca tidak menutup {$context}"
        );
    }

    /** Sidik jari baris jurnal untuk membuktikan jurnal tidak berubah. */
    private function fingerprint(FinancialTransaction $journal): string
    {
        return $journal->load('lines')->lines
            ->map(fn ($line): string => $line->account_id.':'.$line->debit.':'.$line->credit)
            ->sort()->implode('|');
    }

    /** Saldo buku besar satu akun (baris terakhir = saldo berjalan). */
    private function ledgerBalance(string $code): float
    {
        $this->actingAsRole('finance-manager');
        $rows = $this->getJson('/api/v1/finance/ledger/'.$this->account($code))->assertOk()->json('data.rows');

        return $rows === [] ? 0.0 : (float) end($rows)['running_balance'];
    }

    /** @return list<array<string, mixed>> */
    private function receivables(): array
    {
        $this->actingAsRole('finance-officer');

        return $this->getJson('/api/v1/finance/receivables?all=1')->assertOk()->json('data');
    }

    // =================================================================
    // §48 — rantai penuh satu tagihan SPP
    // =================================================================

    public function test_siklus_penuh_tagihan_menghasilkan_pendapatan_tetap_650_000(): void
    {
        $invoice = $this->issueInvoice('E2E001', 'PKT-E2E-1');

        // --- 1. akrual: satu jurnal seimbang Dr Piutang / Cr Pendapatan ------
        $accrual = $this->journalFor($invoice, FinancialTransaction::TYPE_INVOICE);
        $this->assertTrue($accrual->isBalanced());
        $this->assertEquals(self::TAGIHAN, (float) $accrual->total_debit);
        $this->assertSame(1, $this->journalCount());

        // Pendapatan sudah diakui pada basis accrual: 650.000, satu kali.
        $this->assertEquals(self::TAGIHAN, $this->recognizedRevenue());
        $this->assertEquals(650000.0, $this->ledgerBalance('4100') + $this->ledgerBalance('4200'));
        $this->assertEquals(self::TAGIHAN, $this->ledgerBalance('1300'));
        $this->assertEquals(self::TAGIHAN, (float) $this->receivables()[0]['remaining_amount']);
        $this->assertBooksClose('setelah akrual');

        // --- 2. pembayaran: uang masuk, TIDAK menambah pendapatan ------------
        $paymentId = $this->payInvoice($invoice);
        $payment = FinancePayment::find($paymentId);

        $settlement = FinancialTransaction::query()
            ->with('lines.account')
            ->where('reference_type', FinancePayment::class)
            ->where('reference_id', $paymentId)
            ->firstOrFail();
        $sides = $settlement->lines->mapWithKeys(fn ($l): array => [
            $l->account->code => [(float) $l->debit, (float) $l->credit],
        ]);

        $this->assertEquals([self::TAGIHAN, 0.0], $sides['1100']);
        $this->assertEquals([0.0, self::TAGIHAN], $sides['1300']);
        // Tidak ada kredit pendapatan pada jurnal pembayaran (§7: revenue dobel).
        $this->assertFalse($sides->has('4100'));
        $this->assertFalse($sides->has('4200'));

        // --- 3. kuitansi menunjuk payment + invoice ---------------------------
        $receipt = FinanceReceipt::query()->where('payment_id', $paymentId)->firstOrFail();
        $this->assertSame($invoice->id, (int) $receipt->invoice_id);
        $this->assertEquals(self::TAGIHAN, (float) $receipt->amount);

        // --- 4. AR tutup, kas naik, pendapatan TETAP 650.000 ------------------
        $invoice->refresh();
        $this->assertSame(FinanceInvoice::STATUS_PAID, $invoice->status);
        $this->assertEquals(0.0, (float) $invoice->remaining_amount);
        $this->assertSame([], $this->receivables());

        $this->assertEquals(self::TAGIHAN, $this->recognizedRevenue());
        $this->assertEquals(self::TAGIHAN, $this->ledgerBalance('1100'));
        $this->assertEquals(0.0, $this->ledgerBalance('1300'));
        // Kas konkret harus sama persis dengan buku besarnya (§27).
        $this->assertEquals(650000.0, (float) $this->cash()->fresh()->current_balance);

        // --- 5. laporan tutup semua ------------------------------------------
        $labaRugi = collect($this->reportRows('income_statement'))->keyBy('account_name');
        $this->assertEquals(self::TAGIHAN, (float) $labaRugi['Total Pendapatan']['amount']);
        $this->assertEquals(0.0, (float) $labaRugi['Total Beban']['amount']);
        $this->assertEquals(self::TAGIHAN, (float) $labaRugi['Laba (Rugi) Bersih']['amount']);

        $arusKas = collect($this->reportRows('cash_flow'))->keyBy('description');
        $this->assertEquals(self::TAGIHAN, (float) $arusKas['Pembayaran (masuk)']['amount']);
        $this->assertEquals(self::TAGIHAN, (float) $arusKas['Saldo Akhir Kas & Bank']['amount']);

        $this->assertSame(2, $this->journalCount());
        $this->assertBooksClose('setelah pembayaran');

        // --- 6. rantai §38 dari jurnal pembayaran sampai santri ---------------
        $this->actingAsRole('auditor');
        $trail = collect($this->getJson("/api/v1/finance/journals/{$settlement->id}/trace")
            ->assertOk()->json('data.trace.trail'));

        $this->assertSame(
            ['journal', 'payment', 'receipt', 'invoice', 'billing', 'student'],
            $trail->pluck('stage')->all(),
        );
        $this->assertSame('E2E001', $trail->last()['number']);
    }

    // =================================================================
    // §9 — basis cash adalah kebijakan, bukan jalur kode berbeda
    // =================================================================

    public function test_basis_cash_mengakui_pendapatan_hanya_saat_uang_masuk(): void
    {
        $this->actingAsRole('finance-admin');
        $this->putJson('/api/v1/finance/settings', ['settings' => ['accounting_basis' => 'cash']])->assertOk();

        $invoice = $this->issueInvoice('E2E002', 'PKT-E2E-2');

        // Cash basis: invoice adalah dokumen, bukan pengakuan (§9, §11).
        $this->assertSame(0, $this->journalCount());
        $this->assertEquals(0.0, $this->recognizedRevenue());
        $this->assertEquals(self::TAGIHAN, (float) $this->receivables()[0]['remaining_amount']);

        $paymentId = $this->payInvoice($invoice);

        $jurnal = FinancialTransaction::query()->with('lines.account')
            ->where('reference_type', FinancePayment::class)->where('reference_id', $paymentId)->firstOrFail();
        $sides = $jurnal->lines->mapWithKeys(fn ($l): array => [
            $l->account->code => [(float) $l->debit, (float) $l->credit],
        ]);

        // Pendapatan diakui di sini — dan hanya di sini.
        $this->assertEquals(self::SPP, $sides['4100'][1]);
        $this->assertEquals(self::ASRAMA, $sides['4200'][1]);
        $this->assertEquals(self::TAGIHAN, $sides['1100'][0]);
        $this->assertEquals(self::TAGIHAN, $this->recognizedRevenue());
        $this->assertSame(1, $this->journalCount());
        $this->assertBooksClose('cash basis');
    }

    // =================================================================
    // §15–§18 — satu pembayaran, banyak invoice
    // =================================================================

    public function test_satu_pembayaran_multi_invoice_tetap_terlacak_per_invoice(): void
    {
        $a = $this->issueInvoice('E2E003', 'PKT-E2E-3');
        $b = $this->issueInvoice('E2E004', 'PKT-E2E-4');
        $this->assertSame(2, $this->journalCount());

        $this->actingAsRole('finance-admin');
        $paymentId = (int) $this->postJson('/api/v1/finance/payments', [
            'amount' => 1300000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
            'allocations' => [
                ['invoice_id' => $a->id, 'amount' => self::TAGIHAN],
                ['invoice_id' => $b->id, 'amount' => 650000],
            ],
        ])->assertCreated()->json('data.id');

        $jurnal = FinancialTransaction::query()
            ->where('reference_type', FinancePayment::class)->where('reference_id', $paymentId)->firstOrFail();

        // Satu jurnal kas masuk (1,3 jt), rincian per invoice tetap tersimpan (§17).
        $this->assertEquals(1300000.0, (float) $jurnal->total_debit);
        $this->assertSame(
            [
                ['invoice_id' => (int) $a->id, 'amount' => 650000.0],
                ['invoice_id' => (int) $b->id, 'amount' => 650000.0],
            ],
            array_map(
                fn (array $row): array => [
                    'invoice_id' => (int) $row['invoice_id'],
                    'amount' => (float) $row['amount'],
                ],
                $jurnal->metadata['allocations'],
            ),
        );
        $this->assertEquals(1300000.0, $this->recognizedRevenue());
        $this->assertEquals(1300000.0, $this->ledgerBalance('1100'));
        $this->assertSame(3, $this->journalCount());
        $this->assertBooksClose('multi invoice');

        // Penelusuran menampilkan KEDUA invoice pada satu rantai (§38).
        $this->actingAsRole('auditor');
        $trail = collect($this->getJson("/api/v1/finance/journals/{$jurnal->id}/trace")
            ->assertOk()->json('data.trace.trail'));

        $this->assertSame(
            [$a->invoice_number, $b->invoice_number],
            $trail->where('stage', 'invoice')->pluck('number')->all(),
        );
        $this->assertCount(2, $trail->where('stage', 'billing'));

        // Pembayaran ganda atas tagihan yang sudah lunas: ditolak, tanpa efek.
        $before = [$this->journalCount(), (float) $this->cash()->fresh()->current_balance];
        $this->payOver($a);
        $this->assertSame($before[0], $this->journalCount());
        $this->assertSame($before[1], (float) $this->cash()->fresh()->current_balance);
        $a->refresh();
        $this->assertEquals(self::TAGIHAN, (float) $a->paid_amount);
    }

    private function payOver(FinanceInvoice $invoice): void
    {
        $this->actingAsRole('finance-admin');
        $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => self::TAGIHAN,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
        ])->assertStatus(422);
    }

    // =================================================================
    // §21 — menunggu verifikasi tidak boleh memposting jurnal
    // =================================================================

    public function test_pembayaran_pending_verifikasi_tidak_memposting_jurnal(): void
    {
        $invoice = $this->issueInvoice('E2E005', 'PKT-E2E-5');

        $paymentId = $this->payInvoice($invoice, self::TAGIHAN, 'bank_transfer');
        $payment = FinancePayment::find($paymentId);
        $this->assertSame(FinancePayment::STATUS_PENDING, $payment->status);

        // Belum ada jurnal pembayaran, bank tidak bergerak, AR utuh (§21).
        $this->assertSame(1, $this->journalCount());
        $this->assertSame(0.0, $this->ledgerBalance('1200'));
        $this->assertEquals(self::TAGIHAN, (float) $invoice->fresh()->remaining_amount);
        $this->assertEquals(self::TAGIHAN, $this->recognizedRevenue());
        $this->assertNull(FinanceReceipt::query()->where('payment_id', $paymentId)->first());

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/payments/{$paymentId}/verify")->assertOk();

        // Setelah verifikasi: jurnal, saldo bank, kuitansi, dan AR tutup.
        $this->assertSame(2, $this->journalCount());
        $this->assertEquals(self::TAGIHAN, $this->ledgerBalance('1200'));
        $this->assertEquals(650000.0, (float) $this->bank()->fresh()->current_balance);
        $this->assertEquals(0.0, (float) FinanceInvoice::find($invoice->id)->remaining_amount);
        $this->assertNotNull(FinanceReceipt::query()->where('payment_id', $paymentId)->first());
        $this->assertEquals(self::TAGIHAN, $this->recognizedRevenue());
        $this->assertBooksClose('setelah verifikasi');
    }

    // =================================================================
    // §49 — periode tertutup menolak posting, tanpa jejak
    // =================================================================

    public function test_periode_closed_menolak_pembayaran_dan_tidak_meninggalkan_jejak(): void
    {
        $invoice = $this->issueInvoice('E2E006', 'PKT-E2E-6');
        $journalsBefore = $this->journalCount();

        $period = FinancePeriod::query()
            ->whereDate('start_date', '<=', Carbon::today())
            ->whereDate('end_date', '>=', Carbon::today())
            ->firstOrFail();

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/periods/{$period->id}/close")->assertOk();

        $this->actingAsRole('finance-admin');
        $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => self::TAGIHAN,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
        ])->assertStatus(422)->assertJsonValidationErrors('date');

        // Rollback penuh: tidak ada payment yatim, tidak ada jurnal, saldo utuh.
        $this->assertSame(0, FinancePayment::query()->count());
        $this->assertSame($journalsBefore, $this->journalCount());
        $this->assertEquals(self::TAGIHAN, (float) $invoice->fresh()->remaining_amount);
        $this->assertEquals(0.0, (float) $this->cash()->fresh()->current_balance);
        $this->assertEquals(self::TAGIHAN, $this->recognizedRevenue());

        // Buka kembali periode → pembayaran yang sama berhasil (§49, koreksi
        // lewat pembalikan bukan penghapusan).
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/periods/{$period->id}/reopen")->assertOk();

        $this->payInvoice($invoice);
        $this->assertSame($journalsBefore + 1, $this->journalCount());
        $this->assertEquals(0.0, (float) $invoice->fresh()->remaining_amount);
    }

    // =================================================================
    // §27, §61 — kas/bank konkret selalu sama dengan buku besarnya
    // =================================================================

    public function test_transaksi_pembelian_dan_beban_damai_dengan_buku_besar(): void
    {
        $this->openCash(500000);

        // Pendapatan: SPP 650rb + tagihan lain (KEGIATAN) 100rb, keduanya lunas.
        $spp = $this->issueInvoice('E2E007', 'PKT-E2E-7');
        $this->payInvoice($spp);

        $lain = $this->issueOtherBilling('E2E007', 'KEGIATAN', 100000, 'E2E-KERIATAN');
        $this->payInvoice($lain);

        // Beban: invoice vendor 300rb dibayar tunai + pengeluaran 50rb tunai.
        $this->actingAsRole('finance-admin');
        $vendorId = (int) $this->postJson('/api/v1/finance/vendor-invoices', [
            'vendor_id' => FinanceVendor::query()->firstOrCreate(
                ['code' => 'V-E2E'],
                ['name' => 'Vendor E2E', 'vendor_type' => 'supplier', 'status' => 'active', 'is_active' => true],
            )->id,
            'invoice_date' => '2026-09-05',
            'due_date' => '2026-09-20',
            'subtotal' => 300000,
            'tax' => 0,
            'account_id' => $this->account('5200'),
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/finance/vendor-invoices/{$vendorId}/pay", [
            'amount' => 300000,
            'cash_account_id' => $this->cash()->id,
        ])->assertOk();

        $this->actingAsRole('kepala-unit');
        $reqId = (int) $this->postJson('/api/v1/finance/expense-requests', [
            'cost_center_id' => FinanceCostCenter::query()->where('code', 'CC-SEKOLAH')->value('id'),
            'account_id' => $this->account('5200'),
            'amount' => 50000,
            'description' => 'ATK kantor',
            'expense_date' => '2026-09-08',
            'submit' => true,
        ])->assertCreated()->json('data.id');

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/expense-requests/{$reqId}/approve")->assertOk();

        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/expense-requests/{$reqId}/pay", [
            'cash_account_id' => $this->cash()->id,
        ])->assertCreated();

        // --- laporan laba rugi dari buku besar (§44) --------------------------
        $labaRugi = collect($this->reportRows('income_statement'))->keyBy('account_name');
        $this->assertEquals(750000.0, (float) $labaRugi['Total Pendapatan']['amount']);
        $this->assertEquals(350000.0, (float) $labaRugi['Total Beban']['amount']);
        $this->assertEquals(400000.0, (float) $labaRugi['Laba (Rugi) Bersih']['amount']);

        // --- kas: 500rb + 650rb + 100rb − 300rb − 50rb -----------------------
        $this->assertEquals(900000.0, $this->ledgerBalance('1100'));
        $this->assertSame(900000.0, (float) $this->cash()->fresh()->current_balance);

        // Saldo akun kas dari endpoint saldo + subledger harus angka yang sama.
        $this->actingAsRole('finance-manager');
        $saldo = $this->getJson('/api/v1/finance/accounts/'.$this->account('1100').'/balance')
            ->assertOk()->json('data.balance');
        $this->assertEquals(900000.0, $saldo['balance']);
        $this->assertEquals($saldo['balance'], $saldo['subledger_balance']);

        // Piutang & hutang bersih: semuanya sudah dibayar.
        $this->assertSame([], $this->receivables());
        $this->actingAsRole('finance-manager');
        $this->assertSame([], $this->getJson('/api/v1/finance/payables?all=1')->assertOk()->json('data'));

        $this->assertBooksClose('buku besar campuran');
    }

    // =================================================================
    // §28 — refund: pembayaran asli tetap immutable
    // =================================================================

    public function test_refund_mengembalikan_piutang_tanpa_menghapus_pembayaran_asli(): void
    {
        $this->openCash(1000000);
        $invoice = $this->issueInvoice('E2E008', 'PKT-E2E-8');
        $paymentId = $this->payInvoice($invoice);

        $settlement = FinancialTransaction::query()
            ->where('reference_type', FinancePayment::class)->where('reference_id', $paymentId)->firstOrFail();
        $fingerprint = $this->fingerprint($settlement);
        $journalsBefore = $this->journalCount();

        $this->actingAsRole('finance-admin');
        $refundId = (int) $this->postJson("/api/v1/finance/payments/{$paymentId}/refunds", [
            'amount' => 200000,
            'reason' => 'Kelebihan bayar kegiatan',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/finance/refunds/{$refundId}/approve")->assertOk();
        $this->postJson("/api/v1/finance/refunds/{$refundId}/complete")->assertOk();

        // Jurnal pembayaran asli tidak berubah sedikit pun; refund = jurnal baru.
        $this->assertSame($fingerprint, $this->fingerprint($settlement->fresh()));
        $this->assertSame(FinancialTransaction::STATUS_POSTED, $settlement->status);
        $this->assertSame($journalsBefore + 1, $this->journalCount());

        $refund = FinancialTransaction::query()->with('lines.account')
            ->where('reference_type', FinanceRefund::class)->where('reference_id', $refundId)->firstOrFail();
        $sides = $refund->lines->mapWithKeys(fn ($l): array => [
            $l->account->code => [(float) $l->debit, (float) $l->credit],
        ]);
        $this->assertEquals([200000.0, 0.0], $sides['1300']);
        $this->assertEquals([0.0, 200000.0], $sides['1100']);

        // Dampaknya ke penagihan & laporan: AR terbuka lagi 200rb, pendapatan
        // TIDAK menjadi negatif (§28: refund bukan pengurang pendapatan).
        $invoice->refresh();
        $this->assertEquals(200000.0, (float) $invoice->remaining_amount);
        $this->assertSame(FinanceInvoice::STATUS_PARTIAL, $invoice->status);
        $this->assertEquals(200000.0, (float) $this->receivables()[0]['remaining_amount']);
        $this->assertEquals(self::TAGIHAN, $this->recognizedRevenue());
        // Kas: 1.000.000 awal + 650.000 masuk − 200.000 refund.
        $this->assertEquals(1450000.0, $this->ledgerBalance('1100'));
        $this->assertEquals(200000.0, $this->ledgerBalance('1300'));
        $this->assertBooksClose('setelah refund');
    }

    // =================================================================
    // §33 — write-off: piutang hapus, pendapatan tidak bergerak
    // =================================================================

    public function test_write_off_hanya_memindahkan_angka_ke_beban_dan_terhubung_di_penelusuran(): void
    {
        $invoice = $this->issueInvoice('E2E009', 'PKT-E2E-9');
        $accrual = $this->journalFor($invoice, FinancialTransaction::TYPE_INVOICE);

        $this->actingAsRole('finance-officer');
        $adjustmentId = (int) $this->postJson("/api/v1/finance/invoices/{$invoice->id}/write-off", [
            'amount' => 250000,
            'reason' => 'Santri keluar, piutang tak tertagih',
            'adjustment_date' => '2026-09-20',
        ])->assertCreated()->json('data.id');

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/adjustments/{$adjustmentId}/approve")->assertOk();
        $this->postJson("/api/v1/finance/adjustments/{$adjustmentId}/post")->assertOk();

        // Beban write-off menyerap piutang; pendapatan tetap 650rb seperti semula.
        $this->assertEquals(250000.0, $this->ledgerBalance('5700'));
        $this->assertEquals(400000.0, $this->ledgerBalance('1300'));
        $this->assertEquals(self::TAGIHAN, $this->recognizedRevenue());
        $this->assertEquals(250000.0, (float) $this->reportRow('income_statement', 'Total Beban')['amount']);

        // AR tinggal sisa yang belum dihapus.
        $row = collect($this->receivables())->firstWhere('id', $invoice->id);
        $this->assertEquals(400000.0, (float) $row['remaining_amount']);
        $this->assertEquals(250000.0, (float) $row['written_off_amount']);

        // Penelusuran jurnal akrual menemukan jurnal write-off, meski referensi
        // jurnal itu adalah adjustment (§17/§38: metadata yang menyatukan).
        $this->actingAsRole('auditor');
        $trace = $this->getJson("/api/v1/finance/journals/{$accrual->id}/trace")->assertOk()->json('data.trace');
        $related = collect($trace['related_journals'])->pluck('type')->all();

        $this->assertContains(FinancialTransaction::TYPE_WRITE_OFF, $related);
        $this->assertCount(2, $related);
        $this->assertBooksClose('setelah write-off');
    }

    // =================================================================
    // §50 — keseimbangan dijaga di setiap jalur pembukuan
    // =================================================================

    public function test_semua_jurnal_hasil_alur_bisnis_seimbang_dan_bisa_dibaca_dari_api(): void
    {
        $this->openCash(1000000);

        $invoice = $this->issueInvoice('E2E010', 'PKT-E2E-10');
        $this->payInvoice($invoice);

        $vendorId = $this->receiveVendor(400000);
        $this->payVendor($vendorId, 150000);

        // Saldo awal + akrual invoice + pelunasan + penerimaan vendor + bayar vendor.
        $this->assertSame(5, $this->journalCount());

        $this->actingAsRole('finance-manager');
        $rows = $this->getJson('/api/v1/finance/journals?all=1')->assertOk();

        foreach ($rows->json('data') as $journal) {
            $this->assertTrue($journal['is_balanced'], "Jurnal {$journal['transaction_number']} tidak seimbang");
            $this->assertEquals($journal['total_debit'], $journal['total_credit']);
            $this->assertNotEmpty($journal['lines']);
            $this->assertNotNull($journal['posted_at']);
            $this->assertNotNull($journal['document_number'] ?? $journal['description']);
        }

        $totals = $rows->json('meta.totals');
        $this->assertSame(5, $totals['journals']);
        $this->assertEquals($totals['debit'], $totals['credit']);
        $this->assertTrue($totals['balanced']);

        // Tidak ada jurnal yang barisnya tidak seimbang (orphan line) — engine
        // adalah satu-satunya penulis (§6).
        $unbalanced = FinancialTransaction::query()->with('lines')->get()
            ->filter(fn (FinancialTransaction $t): bool => abs((float) $t->total_debit - (float) $t->lines->sum('debit')) > 0.005
                || abs((float) $t->total_credit - (float) $t->lines->sum('credit')) > 0.005);
        $this->assertCount(0, $unbalanced);

        $this->assertBooksClose('akhir');
    }

    private function receiveVendor(float $subtotal): int
    {
        $this->actingAsRole('finance-admin');

        return (int) $this->postJson('/api/v1/finance/vendor-invoices', [
            'vendor_id' => FinanceVendor::query()->firstOrCreate(
                ['code' => 'V-E2E-2'],
                ['name' => 'Vendor E2E 2', 'vendor_type' => 'supplier', 'status' => 'active', 'is_active' => true],
            )->id,
            'invoice_date' => '2026-09-02',
            'due_date' => '2026-09-20',
            'subtotal' => $subtotal,
            'tax' => 0,
            'account_id' => $this->account('5200'),
        ])->assertCreated()->json('data.id');
    }

    private function payVendor(int $id, float $amount): void
    {
        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/vendor-invoices/{$id}/pay", [
            'amount' => $amount,
            'cash_account_id' => $this->cash()->id,
        ])->assertOk();
    }

    public function test_tagihan_yang_dibatalkan_membalik_jurnal_tanpa_meninggalkan_saldo(): void
    {
        $invoice = $this->issueInvoice('E2E011', 'PKT-E2E-11');
        $this->assertEquals(self::TAGIHAN, $this->ledgerBalance('1300'));
        $this->assertEquals(650000.0, $this->recognizedRevenue());

        // Invoice read-only (§16): pembatalan terjadi di sisi billing.
        $this->actingAsRole('finance-admin');
        $this->postJson('/api/v1/finance/billings/'.$invoice->billing_id.'/cancel', [
            'reason' => 'Salah generate tagihan',
        ])->assertOk();

        // Jurnal asli tetap ada; pembalikan menolohnya sehingga saldo nol (§51).
        $this->assertSame(2, $this->journalCount());
        $this->assertEquals(0.0, $this->ledgerBalance('1300'));
        $this->assertEquals(0.0, $this->ledgerBalance('4100'));
        $this->assertEquals(0.0, $this->ledgerBalance('4200'));
        $this->assertEquals(0.0, $this->recognizedRevenue());
        $this->assertSame([], $this->receivables());
        $this->assertEquals(
            FinanceInvoice::STATUS_CANCELLED,
            (string) $invoice->fresh()->status,
        );
        $this->assertBooksClose('setelah pembatalan tagihan');
    }
}
