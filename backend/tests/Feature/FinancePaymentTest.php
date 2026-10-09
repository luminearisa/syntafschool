<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Models\FinanceAccount;
use App\Models\FinanceBankAccount;
use App\Models\FinanceBilling;
use App\Models\FinanceCashAccount;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\FinanceReceipt;
use App\Models\FinanceRefund;
use App\Models\FinancialTransaction;
use App\Models\FinancialTransactionLine;
use App\Models\Student;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pembayaran, kuitansi, refund, dan webhook gateway (§19–§29, §66) lewat HTTP.
 *
 * Yang diverifikasi: tunai langsung melunaskan invoice + memposting jurnal
 * Dr Kas/Cr Piutang + menerbitkan kuitansi (§24); metode manual lahir pending
 * tanpa menyentuh saldo invoice/jurnal (§21); verifikasi menyelesaikan; penolakan
 * membatalkan tanpa jurnal; pembatalan membalik jurnal + memulihkan invoice +
 * mengosongkan kuitansi (§25, §29, §51); refund mengajukan→menyetujui→menyelesaikan
 * dengan jurnal keluar; webhook gateway idempoten (tidak dobel bayar §66); guard permission.
 */
class FinancePaymentTest extends TestCase
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

    private function student(string $nis = 'P001'): Student
    {
        return Student::query()->firstOrCreate(
            ['nis' => $nis],
            ['full_name' => 'Santri '.$nis, 'is_active' => true],
        );
    }

    private function feeType(string $code): FinanceFeeType
    {
        return FinanceFeeType::query()->where('code', $code)->firstOrFail();
    }

    private function cash(string $code = 'KAS-SEKOLAH'): FinanceCashAccount
    {
        return FinanceCashAccount::query()->where('code', $code)->firstOrFail();
    }

    private function bank(string $code = 'BANK-OPS'): FinanceBankAccount
    {
        return FinanceBankAccount::query()->where('code', $code)->firstOrFail();
    }

    private function account(string $code): int
    {
        return (int) FinanceAccount::query()->where('code', $code)->value('id');
    }

    /** Paket SPP 350.000 + Uang Asrama 500.000 = 850.000. */
    private function package(string $code = 'PKT-BAYAR'): FinanceFeePackage
    {
        $package = FinanceFeePackage::query()->firstOrCreate(
            ['code' => $code],
            ['name' => 'Paket Bayar', 'boarding_type' => 'all', 'is_active' => true],
        );

        if ($package->items()->count() === 0) {
            $package->items()->createMany([
                ['fee_type_id' => $this->feeType('SPP')->id, 'amount' => 350000, 'frequency' => 'monthly', 'is_active' => true],
                ['fee_type_id' => $this->feeType('UANG_ASRAMA')->id, 'amount' => 500000, 'frequency' => 'monthly', 'is_active' => true],
            ]);
        }

        return $package;
    }

    /** Billing tergenerate + invoice terbit (jurnal akrual sudah diposting). Panggil sebagai finance-admin. */
    private function issuedInvoice(Student $student, string $period = '2026-09', string $pkg = 'PKT-BAYAR'): FinanceInvoice
    {
        $package = $this->package($pkg);

        $billingId = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id,
            'period_key' => $period,
            'student_ids' => [$student->id],
            'due_date' => '2026-09-30',
        ])->assertOk()->json('data.billings.0');

        return FinanceInvoice::find(
            $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated()->json('data.id')
        );
    }

    /** @return array{0: float, 1: float} total debit & credit baris jurnal payment */
    private function paymentJournalSides(FinancePayment $payment, string $accountCode): array
    {
        $journal = FinancialTransaction::query()
            ->where('reference_type', FinancePayment::class)
            ->where('reference_id', $payment->id)
            ->where('type', FinancialTransaction::TYPE_PAYMENT)
            ->where('status', FinancialTransaction::STATUS_POSTED)
            ->first();

        if ($journal === null) {
            return [0.0, 0.0];
        }

        $line = FinancialTransactionLine::query()
            ->where('finance_transaction_id', $journal->id)
            ->where('account_id', $this->account($accountCode))
            ->first();

        return [(float) ($line?->debit ?? 0), (float) ($line?->credit ?? 0)];
    }

    // =================================================================
    // Pembayaran tunai (§19, §24)
    // =================================================================

    public function test_pembayaran_tunai_melunaskan_invoice_dan_memposting_jurnal(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('P100'));

        $resp = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
        ])->assertCreated();

        $payment = FinancePayment::find($resp->json('data.id'));
        $this->assertSame(FinancePayment::STATUS_PAID, $payment->status);
        $this->assertNotNull($payment->paid_at);

        $invoice->refresh();
        $this->assertEquals(850000.00, (float) $invoice->paid_amount);
        $this->assertEquals(0.00, (float) $invoice->remaining_amount);
        $this->assertSame(FinanceInvoice::STATUS_PAID, $invoice->status);

        // billing ikut lunas
        $billing = FinanceBilling::find($invoice->billing_id);
        $this->assertEquals(850000.00, (float) $billing->paid_amount);
        $this->assertSame(FinanceBilling::STATUS_PAID, $billing->status);

        // jurnal Dr Kas(1100) 850k, Cr Piutang(1300) 850k
        [$debitKas] = $this->paymentJournalSides($payment, '1100');
        [, $creditAr] = $this->paymentJournalSides($payment, '1300');
        $this->assertEquals(850000.00, $debitKas);
        $this->assertEquals(850000.00, $creditAr);

        // saldo kas konkret naik
        $this->assertEquals(850000.00, (float) $this->cash()->current_balance);

        // kuitansi terbit (§24)
        $receipt = FinanceReceipt::query()->where('payment_id', $payment->id)->first();
        $this->assertNotNull($receipt);
        $this->assertFalse((bool) $receipt->is_cancelled);
        $this->assertEquals(850000.00, (float) $receipt->amount);
    }

    public function test_pembayaran_sebagian_menandai_invoice_partial(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('P101'), '2026-09', 'PKT-PARTIAL');

        $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 300000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
        ])->assertCreated();

        $invoice->refresh();
        $this->assertEquals(300000.00, (float) $invoice->paid_amount);
        $this->assertEquals(550000.00, (float) $invoice->remaining_amount);
        $this->assertSame(FinanceInvoice::STATUS_PARTIAL, $invoice->status);
    }

    public function test_pembayaran_melebihi_sisa_tagihan_ditolak(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('P102'), '2026-09', 'PKT-OVER');

        $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 900000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_pembayaran_tanpa_invoice_atau_billing_ditolak(): void
    {
        $this->actingAsRole('finance-admin');

        $this->postJson('/api/v1/finance/payments', [
            'amount' => 100000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
        ])->assertStatus(422);
    }

    public function test_pembayaran_ke_billing_tanpa_invoice_disuruh_terbitkan_invoice(): void
    {
        $this->actingAsRole('finance-admin');
        $package = $this->package('PKT-NOINV');
        $student = $this->student('P103');

        $billingId = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id,
            'period_key' => '2026-09',
            'student_ids' => [$student->id],
        ])->assertOk()->json('data.billings.0');

        $this->postJson('/api/v1/finance/payments', [
            'billing_id' => $billingId,
            'amount' => 850000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
        ])->assertStatus(422)->assertJsonValidationErrors('billing_id');
    }

    // =================================================================
    // Pembayaran manual: pending sampai diverifikasi (§21)
    // =================================================================

    public function test_pembayaran_manual_pending_tidak_menyentuh_saldo_invoice(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('P110'), '2026-09', 'PKT-MANUAL');

        $resp = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'manual_transfer',
            'bank_account_id' => $this->bank()->id,
            'proof_path' => 'bukti/transfer.jpg',
        ])->assertCreated();

        $payment = FinancePayment::find($resp->json('data.id'));
        $this->assertSame(FinancePayment::STATUS_PENDING, $payment->status);

        // saldo invoice & jurnal belum bergerak (§21)
        $invoice->refresh();
        $this->assertEquals(0.00, (float) $invoice->paid_amount);
        $this->assertSame(FinanceInvoice::STATUS_ISSUED, $invoice->status);
        $this->assertSame([0.0, 0.0], $this->paymentJournalSides($payment, '1200'));
        $this->assertNull(FinanceReceipt::query()->where('payment_id', $payment->id)->first());
    }

    public function test_verifikasi_menyelesaikan_pembayaran_pending(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('P111'), '2026-09', 'PKT-VERIFY');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'bank_transfer',
            'bank_account_id' => $this->bank()->id,
        ])->assertCreated()->json('data.id');

        $this->actingAsRole('finance-manager'); // punya payment.verify
        $this->postJson("/api/v1/finance/payments/{$paymentId}/verify")->assertOk();

        $payment = FinancePayment::find($paymentId);
        $this->assertSame(FinancePayment::STATUS_PAID, $payment->status);
        $this->assertNotNull($payment->verified_at);

        $invoice->refresh();
        $this->assertEquals(850000.00, (float) $invoice->paid_amount);

        [$debitBank] = $this->paymentJournalSides($payment, '1200');
        $this->assertEquals(850000.00, $debitBank);
        $this->assertNotNull(FinanceReceipt::query()->where('payment_id', $payment->id)->where('is_cancelled', false)->first());
    }

    public function test_verifikasi_dua_kali_ditolak(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('P112'), '2026-09', 'PKT-DBLVERIFY');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'bank_transfer',
            'bank_account_id' => $this->bank()->id,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/finance/payments/{$paymentId}/verify")->assertOk();
        $this->postJson("/api/v1/finance/payments/{$paymentId}/verify")->assertStatus(422);
    }

    public function test_penolakan_pembayaran_pending_tanpa_jurnal(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('P113'), '2026-09', 'PKT-REJECT');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'manual_transfer',
            'bank_account_id' => $this->bank()->id,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/finance/payments/{$paymentId}/reject", ['reason' => 'Bukti tidak valid'])
            ->assertOk();

        $payment = FinancePayment::find($paymentId);
        $this->assertSame(FinancePayment::STATUS_FAILED, $payment->status);
        $this->assertEquals(0.00, (float) $invoice->refresh()->paid_amount);
        $this->assertSame([0.0, 0.0], $this->paymentJournalSides($payment, '1200'));
    }

    // =================================================================
    // Pembatalan (§25, §29, §51)
    // =================================================================

    public function test_pembatalan_membalik_jurnal_dan_memulihkan_invoice(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('P120'), '2026-09', 'PKT-CANCEL');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
        ])->assertCreated()->json('data.id');

        $this->assertEquals(850000.00, (float) $this->cash()->current_balance);

        $this->postJson("/api/v1/finance/payments/{$paymentId}/cancel", ['reason' => 'Salah input nominal'])
            ->assertOk();

        $payment = FinancePayment::find($paymentId);
        $this->assertSame(FinancePayment::STATUS_CANCELLED, $payment->status);

        // invoice dipulihkan ke belum terbayar
        $invoice->refresh();
        $this->assertEquals(0.00, (float) $invoice->paid_amount);
        $this->assertEquals(850000.00, (float) $invoice->remaining_amount);
        $this->assertSame(FinanceInvoice::STATUS_ISSUED, $invoice->status);

        // saldo kas kembali nol (jurnal dibalik)
        $this->assertEquals(0.00, (float) $this->cash()->current_balance);

        // kuitansi void, tidak dihapus (§25)
        $receipt = FinanceReceipt::query()->where('payment_id', $paymentId)->first();
        $this->assertNotNull($receipt);
        $this->assertTrue((bool) $receipt->is_cancelled);
        $this->assertNotNull($receipt->cancel_reason);

        // payment asli tetap ada (§29)
        $this->assertDatabaseHas('finance_payments', ['id' => $paymentId, 'status' => FinancePayment::STATUS_CANCELLED]);
    }

    public function test_pembatalan_wajib_punya_alasan(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('P121'), '2026-09', 'PKT-CANCEL2');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/finance/payments/{$paymentId}/cancel", [])->assertStatus(422);
    }

    // =================================================================
    // Refund (§29)
    // =================================================================

    public function test_refund_mengembalikan_dana_dan_memulihkan_sisa_invoice(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('P130'), '2026-09', 'PKT-REFUND');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
        ])->assertCreated()->json('data.id');

        $refundId = $this->postJson("/api/v1/finance/payments/{$paymentId}/refunds", [
            'amount' => 200000,
            'reason' => 'Kelebihan bayar kegiatan',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/finance/refunds/{$refundId}/approve")->assertOk();
        $this->postJson("/api/v1/finance/refunds/{$refundId}/complete")->assertOk();

        $refund = FinanceRefund::find($refundId);
        $this->assertSame(FinanceRefund::STATUS_COMPLETED, $refund->status);
        $this->assertNotNull($refund->finance_transaction_id);
        $this->assertNotNull($refund->processed_at);

        // jurnal keluar: Dr Piutang(1300) 200k, Cr Kas(1100) 200k → saldo kas turun
        $this->assertEquals(650000.00, (float) $this->cash()->current_balance);

        // invoice: terbayar 650k, sisa 200k, partial
        $invoice->refresh();
        $this->assertEquals(650000.00, (float) $invoice->paid_amount);
        $this->assertEquals(200000.00, (float) $invoice->remaining_amount);
        $this->assertSame(FinanceInvoice::STATUS_PARTIAL, $invoice->status);

        // payment asli tidak dihapus, status menyesuaikan
        $this->assertDatabaseHas('finance_payments', ['id' => $paymentId]);
        $this->assertSame(FinancePayment::STATUS_PARTIAL, FinancePayment::find($paymentId)->status);
    }

    public function test_refund_melebihi_nominal_pembayaran_ditolak(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('P131'), '2026-09', 'PKT-REFUND2');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/finance/payments/{$paymentId}/refunds", [
            'amount' => 900000,
            'reason' => 'Refund penuh',
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    // =================================================================
    // Kuitansi (§25)
    // =================================================================

    public function test_kuitansi_bisa_dibatalkan_tanpa_dihapus(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('P140'), '2026-09', 'PKT-KUITANSI');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
        ])->assertCreated()->json('data.id');

        $receipt = FinanceReceipt::query()->where('payment_id', $paymentId)->where('is_cancelled', false)->first();

        $this->postJson("/api/v1/finance/receipts/{$receipt->id}/cancel", ['reason' => 'Koreksi dokumen'])
            ->assertOk();

        $receipt->refresh();
        $this->assertTrue((bool) $receipt->is_cancelled);
        $this->assertDatabaseHas('finance_receipts', ['id' => $receipt->id]);
    }

    // =================================================================
    // Pembayaran dari saldo wallet (§61 — dua buku tetap terpisah)
    // =================================================================

    public function test_pembayaran_wallet_mendebit_saldo_dan_memposting_jurnal(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('P170');
        $invoice = $this->issuedInvoice($student, '2026-09', 'PKT-WALLET');

        $wallet = Wallet::create([
            'number' => 'W-P170',
            'student_id' => $student->id,
            'balance' => 1000000,
            'held_balance' => 0,
            'status' => Wallet::STATUS_ACTIVE,
            'opened_at' => now(),
        ]);

        $resp = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'wallet',
            'wallet_id' => $wallet->id,
            // akun penampung dana e-wallet di sisi Finance
            'cash_account_id' => $this->cash()->id,
        ])->assertCreated();

        $payment = FinancePayment::find($resp->json('data.id'));
        $this->assertSame(FinancePayment::STATUS_PAID, $payment->status);

        // buku wallet: saldo berkurang + ada catatan transaksi sendiri (§61)
        $this->assertEquals(150000.00, (float) $wallet->fresh()->balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type' => WalletTransaction::TYPE_WITHDRAW,
        ]);

        // buku Finance: jurnalnya sendiri Dr Kas(1100), Cr Piutang(1300)
        [$debitKas] = $this->paymentJournalSides($payment, '1100');
        [, $creditAr] = $this->paymentJournalSides($payment, '1300');
        $this->assertEquals(850000.00, $debitKas);
        $this->assertEquals(850000.00, $creditAr);

        $this->assertSame(FinanceInvoice::STATUS_PAID, $invoice->refresh()->status);
    }

    public function test_pembayaran_wallet_ditolak_bila_saldo_kurang(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('P171');
        $invoice = $this->issuedInvoice($student, '2026-09', 'PKT-WALLET2');

        $wallet = Wallet::create([
            'number' => 'W-P171',
            'student_id' => $student->id,
            'balance' => 100000,
            'held_balance' => 0,
            'status' => Wallet::STATUS_ACTIVE,
            'opened_at' => now(),
        ]);

        $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'wallet',
            'wallet_id' => $wallet->id,
            'cash_account_id' => $this->cash()->id,
        ])->assertStatus(422);

        // tidak ada uang yang bergerak
        $this->assertEquals(100000.00, (float) $wallet->fresh()->balance);
        $this->assertEquals(0.00, (float) $invoice->refresh()->paid_amount);
    }

    // =================================================================
    // Gateway & webhook idempoten (§20, §66)
    // =================================================================

    public function test_webhook_gateway_idempoten_tidak_dobel_bayar(): void
    {
        $this->app->instance(PaymentGateway::class, new FakeSettlementGateway);

        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('P150'), '2026-09', 'PKT-GATEWAY');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'payment_gateway',
            'bank_account_id' => $this->bank()->id,
            'gateway' => 'fake',
        ])->assertCreated()->json('data.id');

        $this->assertSame(FinancePayment::STATUS_PENDING, FinancePayment::find($paymentId)->status);

        $payload = ['reference' => FinancePayment::find($paymentId)->payment_number, 'status' => 'paid'];

        // Notifikasi pertama melunaskan.
        $this->postJson('/api/v1/finance/webhooks/payment/fake', $payload)->assertOk();
        // Notifikasi ganda (retry) tidak boleh membayar dua kali.
        $this->postJson('/api/v1/finance/webhooks/payment/fake', $payload)->assertOk();

        $payment = FinancePayment::find($paymentId);
        $this->assertSame(FinancePayment::STATUS_PAID, $payment->status);

        $invoice->refresh();
        $this->assertEquals(850000.00, (float) $invoice->paid_amount, 'Invoice tidak boleh dibayar ganda');

        // hanya satu jurnal pembayaran untuk payment ini
        $journals = FinancialTransaction::query()
            ->where('reference_type', FinancePayment::class)
            ->where('reference_id', $paymentId)
            ->where('type', FinancialTransaction::TYPE_PAYMENT)
            ->count();
        $this->assertSame(1, $journals);
        $this->assertSame(1, FinanceReceipt::query()->where('payment_id', $paymentId)->count());
    }

    public function test_webhook_tanda_tangan_salah_ditolak(): void
    {
        $this->app->instance(PaymentGateway::class, new FakeRejectingGateway);

        $this->postJson('/api/v1/finance/webhooks/payment/fake', ['reference' => 'X', 'status' => 'paid'])
            ->assertStatus(403);
    }

    // =================================================================
    // Guard permission (§3)
    // =================================================================

    public function test_officer_boleh_mencatat_tapi_tidak_boleh_memverifikasi(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('P160'), '2026-09', 'PKT-GUARD1');

        $this->actingAsRole('finance-officer'); // payment.create, tanpa payment.verify
        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'bank_transfer',
            'bank_account_id' => $this->bank()->id,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/finance/payments/{$paymentId}/verify")->assertStatus(403);
    }

    public function test_manager_tidak_boleh_mencatat_pembayaran(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('P161'), '2026-09', 'PKT-GUARD2');

        $this->actingAsRole('finance-manager'); // payment.verify, tanpa payment.create
        $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
        ])->assertStatus(403);
    }

    public function test_auditor_baca_saja(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('P162'), '2026-09', 'PKT-GUARD3');

        $this->actingAsRole('auditor');
        $this->getJson('/api/v1/finance/payments')->assertOk();
        $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
        ])->assertStatus(403);
    }

    // =================================================================
    // Idempotensi pencatatan pembayaran (Phase 5 §4)
    // =================================================================

    public function test_kunci_idempotensi_menahan_pembayaran_kedua(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('P170'));

        $payload = [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
            'idempotency_key' => 'SLIP-KASIR-0091',
        ];

        $first = $this->postJson('/api/v1/finance/payments', $payload)->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v1/finance/payments', $payload)->assertCreated()->json('data.id');

        // Permintaan ulang adalah permintaan yang sama: satu baris pembayaran,
        // dan yang kedua mengembalikan baris pertama apa adanya.
        $this->assertSame($first, $second);
        $this->assertSame(1, FinancePayment::query()->where('invoice_id', $invoice->id)->count());

        // Retry yang bocor ketahuan di sini: jurnal kas, kuitansi, dan terbayar
        // invoice semuanya harus tetap satu.
        $this->assertSame(1, FinancialTransaction::query()
            ->where('reference_type', FinancePayment::class)
            ->where('reference_id', $first)
            ->count());
        $this->assertSame(1, FinanceReceipt::query()->where('payment_id', $first)->count());
        $this->assertEquals(850000.00, (float) $invoice->refresh()->paid_amount);
        $this->assertEquals(0.00, (float) $invoice->remaining_amount);
    }

    public function test_kunci_idempotensi_terikat_pada_pembuatnya(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('P171'));

        $payload = [
            'invoice_id' => $invoice->id,
            'amount' => 400000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
            'idempotency_key' => 'SETORAN-1',
        ];

        $this->postJson('/api/v1/finance/payments', $payload)->assertCreated();

        // Petugas lain yang kebetulan memakai teks kunci sama tidak ditahan:
        // kuncinya unik per pembuat, bukan global.
        $this->actingAsRole('finance-officer');
        $other = $this->postJson('/api/v1/finance/payments', $payload)->assertCreated()->json('data.id');

        $this->assertSame(2, FinancePayment::query()->where('invoice_id', $invoice->id)->count());
        $this->assertEquals(800000.00, (float) $invoice->refresh()->paid_amount);
        $this->assertSame(FinanceInvoice::STATUS_PARTIAL, $invoice->status);
        $this->assertNotNull(FinancePayment::find($other)->idempotency_key);
    }

    public function test_tanpa_kunci_pembayaran_berulang_tetap_dibuat(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('P172'));

        $payload = [
            'invoice_id' => $invoice->id,
            'amount' => 300000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
        ];

        // Dua cicilan tunai tanpa kunci adalah dua pembayaran sah — kunci yang
        // kosong tidak boleh dianggap "kunci yang sama".
        $first = $this->postJson('/api/v1/finance/payments', $payload)->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v1/finance/payments', $payload)->assertCreated()->json('data.id');

        $this->assertNotSame($first, $second);
        $this->assertEquals(600000.00, (float) $invoice->refresh()->paid_amount);
    }
}

/**
 * Gateway palsu untuk tes: tanda tangan selalu sah, webhook menormalkan apa adanya.
 * Meniru penyedia yang melunaskan charge (§66) tanpa HTTP keluar.
 */
class FakeSettlementGateway implements PaymentGateway
{
    public function createPayment(array $intent): array
    {
        return [
            'reference' => $intent['reference'],
            'amount' => (float) $intent['amount'],
            'token' => 'fake-token',
            'redirect_url' => 'https://gateway.test/pay/fake-token',
            'provider_reference' => 'GTW-'.strtoupper($intent['reference']),
            'expires_at' => null,
            'raw' => [],
        ];
    }

    public function checkStatus(string $reference): array
    {
        return ['reference' => $reference, 'status' => 'paid', 'amount' => 0.0, 'provider_reference' => null, 'raw' => []];
    }

    public function handleWebhook(Request $request): array
    {
        return [
            'reference' => (string) $request->input('reference', ''),
            'status' => (string) $request->input('status', ''),
            'amount' => (float) $request->input('amount', 0),
            'provider_reference' => $request->input('provider_reference'),
            'raw' => $request->all(),
        ];
    }

    public function verifySignature(Request $request): bool
    {
        return true;
    }

    public function supports(string $provider): bool
    {
        return $provider === 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }
}

/** Gateway palsu yang menolak semua tanda tangan — untuk menguji penjaga pintu webhook. */
class FakeRejectingGateway extends FakeSettlementGateway
{
    public function verifySignature(Request $request): bool
    {
        return false;
    }
}
