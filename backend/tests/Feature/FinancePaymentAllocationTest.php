<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\FinancePaymentAllocation;
use App\Models\FinanceReceipt;
use App\Models\FinancialTransaction;
use App\Models\FinancialTransactionLine;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Alokasi pembayaran multi-invoice (§15–§18) + kebijakan kelebihan bayar (§19).
 *
 * Yang diverifikasi: satu pembayaran bisa melunaskan banyak invoice lewat baris
 * `finance_payment_allocations` sebagai sumber kebenaran; jurnal tetap seimbang
 * (Dr Kas = Cr Piutang sebesar yang dialokasikan); invoice tidak pernah dianggap
 * lunas sebelum alokasi nyata; kelebihan bayar ditolak secara default (`reject`)
 * atau ditahan sebagai Titipan/Deposit 2200 saat policy `credit_balance` — tidak
 * pernah membuat invoice negatif; pembatalan memulihkan saldo semua invoice.
 */
class FinancePaymentAllocationTest extends TestCase
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

    private function student(string $nis): Student
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

    private function account(string $code): int
    {
        return (int) FinanceAccount::query()->where('code', $code)->value('id');
    }

    /** Paket SPP 350.000 + Uang Asrama 500.000 = 850.000. */
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

    private function issuedInvoice(Student $student, string $period, string $pkg): FinanceInvoice
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

    private function setOverpaymentPolicy(string $policy): void
    {
        $this->patchJson('/api/v1/finance/settings', ['overpayment_policy' => $policy])->assertOk();
    }

    /** @return array{0: float, 1: float} debit & kredit baris jurnal payment untuk satu akun COA. */
    private function journalSides(FinancePayment $payment, string $accountCode): array
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
    // Alokasi multi-invoice (§15–§18)
    // =================================================================

    public function test_satu_pembayaran_melunaskan_banyak_invoice(): void
    {
        $this->actingAsRole('finance-admin');
        $invA = $this->issuedInvoice($this->student('A201'), '2026-09', 'PKT-A');
        $invB = $this->issuedInvoice($this->student('A202'), '2026-09', 'PKT-B');

        $resp = $this->postJson('/api/v1/finance/payments', [
            'amount' => 1700000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
            'allocations' => [
                ['invoice_id' => $invA->id, 'amount' => 850000],
                ['invoice_id' => $invB->id, 'amount' => 850000],
            ],
        ])->assertCreated();

        $payment = FinancePayment::find($resp->json('data.id'));
        $this->assertSame(FinancePayment::STATUS_PAID, $payment->status);
        $this->assertCount(2, FinancePaymentAllocation::where('payment_id', $payment->id)->get());

        $invA->refresh();
        $invB->refresh();
        $this->assertSame(FinanceInvoice::STATUS_PAID, $invA->status);
        $this->assertSame(FinanceInvoice::STATUS_PAID, $invB->status);
        $this->assertEquals(0.00, (float) $invA->remaining_amount);
        $this->assertEquals(0.00, (float) $invB->remaining_amount);

        // Jurnal seimbang: Dr Kas 1,7jt = Cr Piutang 1,7jt.
        [$debitCash, $creditCash] = $this->journalSides($payment, '1100');
        [$debitAr, $creditAr] = $this->journalSides($payment, '1300');
        $this->assertEquals(1700000.00, $debitCash);
        $this->assertEquals(1700000.00, $creditAr);
        $this->assertEquals(0.0, $creditCash);
        $this->assertEquals(0.0, $debitAr);

        // Kuitansi terbit (§24).
        $this->assertNotNull(FinanceReceipt::query()->where('payment_id', $payment->id)->where('is_cancelled', false)->first());
    }

    public function test_alokasi_sebagian_menandai_invoice_partial(): void
    {
        $this->actingAsRole('finance-admin');
        $invA = $this->issuedInvoice($this->student('A210'), '2026-09', 'PKT-P1');
        $invB = $this->issuedInvoice($this->student('A211'), '2026-09', 'PKT-P2');

        $payment = FinancePayment::find(
            $this->postJson('/api/v1/finance/payments', [
                'amount' => 1000000,
                'method' => 'cash',
                'cash_account_id' => $this->cash()->id,
                'allocations' => [
                    ['invoice_id' => $invA->id, 'amount' => 850000],
                    ['invoice_id' => $invB->id, 'amount' => 150000],
                ],
            ])->assertCreated()->json('data.id')
        );

        $this->assertSame(FinancePayment::STATUS_PAID, $payment->status);

        $invA->refresh();
        $invB->refresh();
        $this->assertSame(FinanceInvoice::STATUS_PAID, $invA->status);
        $this->assertSame(FinanceInvoice::STATUS_PARTIAL, $invB->status);
        $this->assertEquals(700000.00, (float) $invB->remaining_amount);
    }

    public function test_alokasi_melebihi_sisa_invoice_ditolak(): void
    {
        $this->actingAsRole('finance-admin');
        $invA = $this->issuedInvoice($this->student('A220'), '2026-09', 'PKT-OVER1');

        $this->postJson('/api/v1/finance/payments', [
            'amount' => 900000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
            'allocations' => [
                ['invoice_id' => $invA->id, 'amount' => 900000],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('allocations');
    }

    public function test_total_alokasi_melebihi_nominal_pembayaran_ditolak(): void
    {
        $this->actingAsRole('finance-admin');
        $invA = $this->issuedInvoice($this->student('A230'), '2026-09', 'PKT-SUM');

        $this->postJson('/api/v1/finance/payments', [
            'amount' => 500000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
            'allocations' => [
                ['invoice_id' => $invA->id, 'amount' => 600000],
            ],
        ])->assertStatus(422);
    }

    public function test_invoice_sama_dialokasikan_dua_kali_ditolak(): void
    {
        $this->actingAsRole('finance-admin');
        $invA = $this->issuedInvoice($this->student('A240'), '2026-09', 'PKT-DUP');

        $this->postJson('/api/v1/finance/payments', [
            'amount' => 850000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
            'allocations' => [
                ['invoice_id' => $invA->id, 'amount' => 400000],
                ['invoice_id' => $invA->id, 'amount' => 450000],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('allocations');
    }

    public function test_allocations_dicampur_invoice_id_ditolak(): void
    {
        $this->actingAsRole('finance-admin');
        $invA = $this->issuedInvoice($this->student('A250'), '2026-09', 'PKT-MIX');

        $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invA->id,
            'amount' => 850000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
            'allocations' => [
                ['invoice_id' => $invA->id, 'amount' => 850000],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('allocations');
    }

    public function test_pembatalan_multi_invoice_memulihkan_semua_saldo(): void
    {
        $this->actingAsRole('finance-admin');
        $invA = $this->issuedInvoice($this->student('A260'), '2026-09', 'PKT-CAN1');
        $invB = $this->issuedInvoice($this->student('A261'), '2026-09', 'PKT-CAN2');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'amount' => 1700000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
            'allocations' => [
                ['invoice_id' => $invA->id, 'amount' => 850000],
                ['invoice_id' => $invB->id, 'amount' => 850000],
            ],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/finance/payments/{$paymentId}/cancel", [
            'reason' => 'Salah input nominal',
        ])->assertOk();

        $invA->refresh();
        $invB->refresh();
        $this->assertEquals(0.00, (float) $invA->paid_amount);
        $this->assertEquals(0.00, (float) $invB->paid_amount);
        $this->assertSame(FinanceInvoice::STATUS_ISSUED, $invA->status);
        $this->assertSame(FinanceInvoice::STATUS_ISSUED, $invB->status);

        // Payment asli tetap ada (§29), status batal, kuitansi ditandai batal (§25).
        $payment = FinancePayment::find($paymentId);
        $this->assertSame(FinancePayment::STATUS_CANCELLED, $payment->status);
        $this->assertTrue((bool) FinanceReceipt::query()->where('payment_id', $paymentId)->value('is_cancelled'));
    }

    // =================================================================
    // Kebijakan kelebihan bayar (§19)
    // =================================================================

    public function test_kelebihan_bayar_ditolak_secara_default(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('A300'), '2026-09', 'PKT-REJ');

        $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 1000000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_policy_credit_balance_menahan_kelebihan_sebagai_deposit(): void
    {
        $this->actingAsRole('finance-admin');
        $this->setOverpaymentPolicy('credit_balance');

        $invoice = $this->issuedInvoice($this->student('A310'), '2026-09', 'PKT-CR');

        $payment = FinancePayment::find(
            $this->postJson('/api/v1/finance/payments', [
                'invoice_id' => $invoice->id,
                'amount' => 1000000,
                'method' => 'cash',
                'cash_account_id' => $this->cash()->id,
            ])->assertCreated()->json('data.id')
        );

        $this->assertSame(FinancePayment::STATUS_PAID, $payment->status);

        $invoice->refresh();
        // Invoice TIDAK negatif: hanya sebesar tagihan yang diakui lunas.
        $this->assertEquals(850000.00, (float) $invoice->paid_amount);
        $this->assertEquals(0.00, (float) $invoice->remaining_amount);
        $this->assertSame(FinanceInvoice::STATUS_PAID, $invoice->status);

        // Alokasi hanya 850k; kelebihan 150k jadi deposit (2200).
        $this->assertEquals(850000.00, (float) FinancePaymentAllocation::where('payment_id', $payment->id)->sum('amount'));

        [$debitCash] = $this->journalSides($payment, '1100');
        [, $creditAr] = $this->journalSides($payment, '1300');
        [, $creditDeposit] = $this->journalSides($payment, '2200');
        $this->assertEquals(1000000.00, $debitCash);
        $this->assertEquals(850000.00, $creditAr);
        $this->assertEquals(150000.00, $creditDeposit);
    }

    public function test_policy_kembali_ke_reject_setelah_diubah(): void
    {
        $this->actingAsRole('finance-admin');
        $this->setOverpaymentPolicy('credit_balance');
        $this->setOverpaymentPolicy('reject');

        $invoice = $this->issuedInvoice($this->student('A320'), '2026-09', 'PKT-BACK');

        $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 1000000,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_pembayaran_multi_invoice_pending_lalu_verifikasi(): void
    {
        $this->actingAsRole('finance-admin');
        $invA = $this->issuedInvoice($this->student('A330'), '2026-09', 'PKT-VF1');
        $invB = $this->issuedInvoice($this->student('A331'), '2026-09', 'PKT-VF2');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'amount' => 1700000,
            'method' => 'bank_transfer',
            'bank_account_id' => FinanceBankAccount::query()->where('code', 'BANK-OPS')->value('id'),
            'allocations' => [
                ['invoice_id' => $invA->id, 'amount' => 850000],
                ['invoice_id' => $invB->id, 'amount' => 850000],
            ],
        ])->assertCreated()->json('data.id');

        // Pending belum menyentuh saldo invoice (§21).
        $invA->refresh();
        $this->assertEquals(0.00, (float) $invA->paid_amount);
        $this->assertSame(FinanceInvoice::STATUS_ISSUED, $invA->status);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/payments/{$paymentId}/verify")->assertOk();

        $invA->refresh();
        $invB->refresh();
        $this->assertSame(FinanceInvoice::STATUS_PAID, $invA->status);
        $this->assertSame(FinanceInvoice::STATUS_PAID, $invB->status);
    }
}
