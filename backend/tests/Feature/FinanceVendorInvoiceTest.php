<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceVendor;
use App\Models\FinanceVendorInvoice;
use App\Models\FinancialTransaction;
use App\Models\User;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Invoice vendor + Accounts Payable (§35, §36) lewat HTTP.
 *
 * Yang diverifikasi: menerima invoice mengakui hutang (Dr beban/aset, Cr Hutang
 * 2100) tanpa menyentuh kas; pembayaran (penuh/sebagian) memposting Dr Hutang /
 * Cr Kas dan memperbarui paid/remaining/status; pembayaran melebihi sisa ditolak;
 * pembatalan membalik jurnal akrual (§51) dan ditolak bila sudah ada pembayaran;
 * guard permission (pencatat vs pembayar §3).
 */
class FinanceVendorInvoiceTest extends TestCase
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
        Sanctum::actingAs($user);

        return $user;
    }

    private function vendor(string $code = 'V-TEST'): FinanceVendor
    {
        return FinanceVendor::query()->firstOrCreate(
            ['code' => $code],
            ['name' => 'Vendor '.$code, 'vendor_type' => FinanceVendor::TYPE_SUPPLIER, 'status' => FinanceVendor::STATUS_ACTIVE, 'is_active' => true],
        );
    }

    private function cash(string $code = 'KAS-SEKOLAH'): FinanceCashAccount
    {
        return FinanceCashAccount::query()->where('code', $code)->firstOrFail();
    }

    private function account(string $code): int
    {
        return (int) FinanceAccount::query()->where('code', $code)->value('id');
    }

    private function fundCash(float $amount, string $code = 'KAS-SEKOLAH'): void
    {
        $this->cash($code)->update(['current_balance' => $amount]);
    }

    private function journalFor(int $invoiceId, string $status = FinancialTransaction::STATUS_POSTED): ?FinancialTransaction
    {
        return FinancialTransaction::query()
            ->with('lines.account')
            ->where('reference_type', FinanceVendorInvoice::class)
            ->where('reference_id', $invoiceId)
            ->where('status', $status)
            ->first();
    }

    /** @return array<string, array{debit: float, credit: float}> */
    private function sides(FinancialTransaction $transaction): array
    {
        return $transaction->lines->mapWithKeys(fn ($line): array => [
            $line->account->code => ['debit' => (float) $line->debit, 'credit' => (float) $line->credit],
        ])->all();
    }

    /** Terima invoice vendor sebagai finance-admin, kembalikan id. */
    private function receiveInvoice(float $subtotal, float $tax = 0, string $accountCode = '5200', ?string $status = null): int
    {
        $this->actingAsRole('finance-admin');

        $payload = [
            'vendor_id' => $this->vendor()->id,
            'invoice_date' => '2026-09-10',
            'due_date' => '2026-10-10',
            'subtotal' => $subtotal,
            'tax' => $tax,
            'account_id' => $this->account($accountCode),
            'cost_center_id' => null,
        ];

        if ($status !== null) {
            $payload['status'] = $status;
        }

        return (int) $this->postJson('/api/v1/finance/vendor-invoices', $payload)->assertCreated()->json('data.id');
    }

    // =================================================================
    // Pengakuan hutang (§35, §36)
    // =================================================================

    public function test_terima_invoice_vendor_mengakui_hutang_tanpa_menyentuh_kas(): void
    {
        $id = $this->receiveInvoice(1000000, 100000);

        $invoice = FinanceVendorInvoice::find($id);
        $this->assertEquals(1100000.0, (float) $invoice->total);
        $this->assertEquals(1100000.0, (float) $invoice->remaining_amount);
        $this->assertEquals(0.0, (float) $invoice->paid_amount);
        $this->assertSame(FinanceVendorInvoice::STATUS_RECEIVED, $invoice->status);

        // Jurnal akrual: Dr Beban (5200), Cr Hutang (2100) 1.100.000.
        $journal = $this->journalFor($id);
        $this->assertNotNull($journal);
        $sides = $this->sides($journal);
        $this->assertEquals(1100000.0, $sides['5200']['debit']);
        $this->assertEquals(1100000.0, $sides['2100']['credit']);

        // Kas tidak berubah.
        $this->assertEquals(0.0, (float) $this->cash()->fresh()->current_balance);
    }

    public function test_pembayaran_penuh_melunaskan_invoice(): void
    {
        $this->fundCash(5000000);
        $id = $this->receiveInvoice(1000000, 100000);

        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/vendor-invoices/{$id}/pay", [
            'amount' => 1100000,
            'cash_account_id' => $this->cash()->id,
        ])->assertOk()
            ->assertJsonPath('data.status', FinanceVendorInvoice::STATUS_PAID)
            ->assertJsonPath('data.remaining_amount', 0);

        $this->assertEquals(3900000.0, (float) $this->cash()->fresh()->current_balance);

        // Jurnal pembayaran: Dr Hutang (2100), Cr Kas (1100) 1.100.000.
        $journals = FinancialTransaction::query()
            ->with('lines.account')
            ->where('reference_type', FinanceVendorInvoice::class)
            ->where('reference_id', $id)
            ->where('status', FinancialTransaction::STATUS_POSTED)
            ->get();

        $this->assertCount(2, $journals);
        $payment = $journals->firstWhere('type', FinancialTransaction::TYPE_PAYMENT);
        $this->assertNotNull($payment);
        $sides = $this->sides($payment);
        $this->assertEquals(1100000.0, $sides['2100']['debit']);
        $this->assertEquals(1100000.0, $sides['1100']['credit']);
    }

    public function test_pembayaran_sebagian_menandai_partially_paid(): void
    {
        $this->fundCash(5000000);
        $id = $this->receiveInvoice(1000000);

        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/vendor-invoices/{$id}/pay", [
            'amount' => 400000,
            'cash_account_id' => $this->cash()->id,
        ])->assertOk()
            ->assertJsonPath('data.status', FinanceVendorInvoice::STATUS_PARTIALLY_PAID)
            ->assertJsonPath('data.paid_amount', 400000)
            ->assertJsonPath('data.remaining_amount', 600000);
    }

    public function test_pembayaran_melebihi_sisa_hutang_ditolak(): void
    {
        $this->fundCash(5000000);
        $id = $this->receiveInvoice(1000000);

        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/vendor-invoices/{$id}/pay", [
            'amount' => 1500000,
            'cash_account_id' => $this->cash()->id,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_pembayaran_wajib_punya_akun_sumber_dana(): void
    {
        $id = $this->receiveInvoice(1000000);

        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/vendor-invoices/{$id}/pay", ['amount' => 500000])
            ->assertStatus(422)->assertJsonValidationErrors('cash_account_id');
    }

    // =================================================================
    // Pembatalan + reversal (§51)
    // =================================================================

    public function test_pembatalan_membalik_jurnal_akrual(): void
    {
        $id = $this->receiveInvoice(1000000);

        $original = $this->journalFor($id);
        $this->assertNotNull($original);

        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/vendor-invoices/{$id}/cancel", ['reason' => 'Salah input invoice'])
            ->assertOk()
            ->assertJsonPath('data.status', FinanceVendorInvoice::STATUS_CANCELLED);

        // Jurnal asli ditandai reversed (tidak dihapus/diedit nominalnya).
        $reversed = $original->fresh();
        $this->assertSame(FinancialTransaction::STATUS_REVERSED, $reversed->status);

        // Ada jurnal pembalik yang menyeimbangkan hutang kembali.
        $counter = FinancialTransaction::query()->find($reversed->reversed_by_transaction_id);
        $this->assertNotNull($counter);
        $this->assertEquals($original->total_debit, $counter->total_credit);
    }

    public function test_invoice_yang_sudah_dibayar_tidak_bisa_dibatalkan(): void
    {
        $this->fundCash(5000000);
        $id = $this->receiveInvoice(1000000);

        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/vendor-invoices/{$id}/pay", ['amount' => 300000, 'cash_account_id' => $this->cash()->id])->assertOk();

        $this->postJson("/api/v1/finance/vendor-invoices/{$id}/cancel", ['reason' => 'Batal'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_draft_bisa_ditandai_diterima_kemudian(): void
    {
        $id = $this->receiveInvoice(800000, 0, '5200', FinanceVendorInvoice::STATUS_DRAFT);

        // Draft belum mengakui hutang.
        $this->assertNull($this->journalFor($id));
        $this->assertSame(FinanceVendorInvoice::STATUS_DRAFT, FinanceVendorInvoice::find($id)->status);

        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/vendor-invoices/{$id}/receive")
            ->assertOk()
            ->assertJsonPath('data.status', FinanceVendorInvoice::STATUS_RECEIVED);

        $this->assertNotNull($this->journalFor($id));
    }

    // =================================================================
    // Guard permission (§3)
    // =================================================================

    public function test_officer_mencatat_tapi_tidak_membayar_invoice_vendor(): void
    {
        $this->actingAsRole('finance-officer');
        $id = (int) $this->postJson('/api/v1/finance/vendor-invoices', [
            'vendor_id' => $this->vendor()->id,
            'invoice_date' => '2026-09-10',
            'subtotal' => 500000,
            'account_id' => $this->account('5200'),
        ])->assertCreated()->json('data.id');

        // officer tidak punya expense.pay → tidak boleh membayar.
        $this->postJson("/api/v1/finance/vendor-invoices/{$id}/pay", ['amount' => 500000, 'cash_account_id' => $this->cash()->id])
            ->assertStatus(403);
    }

    public function test_manager_membayar_tapi_tidak_mencatat_invoice_vendor(): void
    {
        $this->fundCash(5000000);
        $id = $this->receiveInvoice(500000);

        $this->actingAsRole('finance-manager');
        // manager tidak punya vendor.manage → tidak boleh membuat invoice.
        $this->postJson('/api/v1/finance/vendor-invoices', [
            'vendor_id' => $this->vendor()->id,
            'invoice_date' => '2026-09-10',
            'subtotal' => 100000,
            'account_id' => $this->account('5200'),
        ])->assertStatus(403);

        // tapi boleh membayar (expense.pay).
        $this->postJson("/api/v1/finance/vendor-invoices/{$id}/pay", ['amount' => 500000, 'cash_account_id' => $this->cash()->id])
            ->assertOk();
    }

    public function test_auditor_baca_saja(): void
    {
        $id = $this->receiveInvoice(500000);

        $this->actingAsRole('auditor');
        $this->getJson('/api/v1/finance/vendor-invoices')->assertOk();
        $this->getJson("/api/v1/finance/vendor-invoices/{$id}")->assertOk();
        $this->postJson("/api/v1/finance/vendor-invoices/{$id}/pay", ['amount' => 1, 'cash_account_id' => $this->cash()->id])->assertStatus(403);
    }
}
