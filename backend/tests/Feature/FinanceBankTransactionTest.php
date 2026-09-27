<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceBankAccount;
use App\Models\FinanceBankTransaction;
use App\Models\FinancialTransaction;
use App\Models\User;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Transaksi bank standalone (§41) lewat HTTP.
 *
 * Yang diverifikasi: deposit menambah saldo rekening + memposting Dr Bank / Cr
 * contra; withdrawal/fee mengurangi saldo + memposting Dr contra / Cr Bank;
 * penarikan melebihi saldo ditolak (422); tipe transfer ditolak di endpoint ini;
 * contra wajib; jurnal tertaut balik ke baris transaksi; guard permission
 * (pencatat bank.manage vs manager yang hanya bank.view §3).
 */
class FinanceBankTransactionTest extends TestCase
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

    private function bank(string $code = 'BANK-OPS'): FinanceBankAccount
    {
        return FinanceBankAccount::query()->where('code', $code)->firstOrFail();
    }

    private function account(string $code): int
    {
        return (int) FinanceAccount::query()->where('code', $code)->value('id');
    }

    private function fundBank(float $amount, string $code = 'BANK-OPS'): void
    {
        $this->bank($code)->update(['current_balance' => $amount]);
    }

    /** @return array<string, array{debit: float, credit: float}> */
    private function sides(FinancialTransaction $transaction): array
    {
        return $transaction->lines->mapWithKeys(fn ($line): array => [
            $line->account->code => ['debit' => (float) $line->debit, 'credit' => (float) $line->credit],
        ])->all();
    }

    private function journalFor(int $bankTransactionId): ?FinancialTransaction
    {
        return FinancialTransaction::query()
            ->with('lines.account')
            ->where('reference_type', FinanceBankTransaction::class)
            ->where('reference_id', $bankTransactionId)
            ->where('status', FinancialTransaction::STATUS_POSTED)
            ->first();
    }

    // =================================================================
    // Deposit / withdrawal (§41)
    // =================================================================

    public function test_deposit_menambah_saldo_dan_memposting_jurnal(): void
    {
        $this->actingAsRole('finance-officer');

        $id = (int) $this->postJson('/api/v1/finance/bank-transactions', [
            'bank_account_id' => $this->bank()->id,
            'transaction_date' => '2026-09-15',
            'type' => FinanceBankTransaction::TYPE_DEPOSIT,
            'amount' => 1000000,
            'contra_account_id' => $this->account('4500'),
            'reference' => 'SETOR-001',
            'description' => 'Setoran tunai ke bank',
        ])->assertCreated()->json('data.id');

        $this->assertEquals(1000000.0, (float) $this->bank()->fresh()->current_balance);

        $transaction = FinanceBankTransaction::find($id);
        $this->assertNotNull($transaction->finance_transaction_id);

        // Jurnal: Dr Bank (1200), Cr Pendapatan lain (4500).
        $journal = $this->journalFor($id);
        $this->assertNotNull($journal);
        $this->assertSame(FinancialTransaction::TYPE_REVENUE, $journal->type);
        $sides = $this->sides($journal);
        $this->assertEquals(1000000.0, $sides['1200']['debit']);
        $this->assertEquals(1000000.0, $sides['4500']['credit']);

        // Jurnal tertaut balik ke baris transaksi bank.
        $this->assertSame($id, (int) $journal->reference_id);
    }

    public function test_withdrawal_mengurangi_saldo_dan_memposting_jurnal(): void
    {
        $this->fundBank(5000000);
        $this->actingAsRole('finance-officer');

        $id = (int) $this->postJson('/api/v1/finance/bank-transactions', [
            'bank_account_id' => $this->bank()->id,
            'type' => FinanceBankTransaction::TYPE_WITHDRAWAL,
            'amount' => 1500000,
            'contra_account_id' => $this->account('5200'),
        ])->assertCreated()->json('data.id');

        $this->assertEquals(3500000.0, (float) $this->bank()->fresh()->current_balance);

        // Jurnal: Dr Beban utilitas (5200), Cr Bank (1200).
        $journal = $this->journalFor($id);
        $this->assertNotNull($journal);
        $this->assertSame(FinancialTransaction::TYPE_EXPENSE, $journal->type);
        $sides = $this->sides($journal);
        $this->assertEquals(1500000.0, $sides['5200']['debit']);
        $this->assertEquals(1500000.0, $sides['1200']['credit']);
    }

    public function test_withdrawal_melebihi_saldo_ditolak(): void
    {
        $this->fundBank(500000);
        $this->actingAsRole('finance-officer');

        $this->postJson('/api/v1/finance/bank-transactions', [
            'bank_account_id' => $this->bank()->id,
            'type' => FinanceBankTransaction::TYPE_WITHDRAWAL,
            'amount' => 1000000,
            'contra_account_id' => $this->account('5200'),
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        // Saldo tidak berubah.
        $this->assertEquals(500000.0, (float) $this->bank()->fresh()->current_balance);
    }

    public function test_tipe_transfer_ditolak_di_endpoint_transaksi_bank(): void
    {
        $this->actingAsRole('finance-officer');

        $this->postJson('/api/v1/finance/bank-transactions', [
            'bank_account_id' => $this->bank()->id,
            'type' => FinanceBankTransaction::TYPE_TRANSFER,
            'amount' => 100000,
            'contra_account_id' => $this->account('1100'),
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_contra_account_wajib_diisi(): void
    {
        $this->actingAsRole('finance-officer');

        $this->postJson('/api/v1/finance/bank-transactions', [
            'bank_account_id' => $this->bank()->id,
            'type' => FinanceBankTransaction::TYPE_DEPOSIT,
            'amount' => 100000,
        ])->assertStatus(422)->assertJsonValidationErrors('contra_account_id');
    }

    public function test_nominal_harus_positif(): void
    {
        $this->actingAsRole('finance-officer');

        $this->postJson('/api/v1/finance/bank-transactions', [
            'bank_account_id' => $this->bank()->id,
            'type' => FinanceBankTransaction::TYPE_DEPOSIT,
            'amount' => 0,
            'contra_account_id' => $this->account('4500'),
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    // =================================================================
    // Guard permission (§3)
    // =================================================================

    public function test_manager_tidak_bisa_mencatat_transaksi_bank(): void
    {
        $this->actingAsRole('finance-manager');

        $this->postJson('/api/v1/finance/bank-transactions', [
            'bank_account_id' => $this->bank()->id,
            'type' => FinanceBankTransaction::TYPE_DEPOSIT,
            'amount' => 100000,
            'contra_account_id' => $this->account('4500'),
        ])->assertStatus(403);

        // Tapi boleh membaca (bank.view).
        $this->getJson('/api/v1/finance/bank-transactions')->assertOk();
    }

    public function test_auditor_baca_saja(): void
    {
        $this->actingAsRole('finance-officer');
        $id = (int) $this->postJson('/api/v1/finance/bank-transactions', [
            'bank_account_id' => $this->bank()->id,
            'type' => FinanceBankTransaction::TYPE_DEPOSIT,
            'amount' => 100000,
            'contra_account_id' => $this->account('4500'),
        ])->assertCreated()->json('data.id');

        $this->actingAsRole('auditor');
        $this->getJson('/api/v1/finance/bank-transactions')->assertOk();
        $this->getJson("/api/v1/finance/bank-transactions/{$id}")->assertOk();
        $this->postJson('/api/v1/finance/bank-transactions', [
            'bank_account_id' => $this->bank()->id,
            'type' => FinanceBankTransaction::TYPE_DEPOSIT,
            'amount' => 100000,
            'contra_account_id' => $this->account('4500'),
        ])->assertStatus(403);
    }
}
