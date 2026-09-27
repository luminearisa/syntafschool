<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceBankAccount;
use App\Models\FinanceBankTransaction;
use App\Models\FinanceReconciliation;
use App\Models\FinanceReconciliationItem;
use App\Models\User;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rekonsiliasi bank (§44, §45) lewat HTTP.
 *
 * Yang diverifikasi: open menyimpan snapshot saldo buku (system_balance) +
 * difference awal; baris koran bisa dicocokkan ke transaksi sistem (matched)
 * atau dibiarkan unmatched; finalize selisih nol → reconciled, selain itu →
 * discrepancy; rekonsiliasi final tidak bisa diubah; baris milik rekonsiliasi
 * lain ditolak; guard permission (auditor hanya reconciliation.view §3).
 */
class FinanceReconciliationTest extends TestCase
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

    /** Buka rekonsiliasi sebagai finance-officer, kembalikan id. */
    private function openRecon(float $bankBalance, string $statementDate = '2026-09-30'): int
    {
        $this->actingAsRole('finance-officer');

        return (int) $this->postJson('/api/v1/finance/reconciliations', [
            'bank_account_id' => $this->bank()->id,
            'statement_date' => $statementDate,
            'bank_balance' => $bankBalance,
        ])->assertCreated()->json('data.id');
    }

    /** Catat deposit bank sebagai officer, kembalikan id transaksi bank. */
    private function recordDeposit(float $amount): int
    {
        $this->actingAsRole('finance-officer');

        return (int) $this->postJson('/api/v1/finance/bank-transactions', [
            'bank_account_id' => $this->bank()->id,
            'type' => FinanceBankTransaction::TYPE_DEPOSIT,
            'amount' => $amount,
            'contra_account_id' => $this->account('4500'),
        ])->assertCreated()->json('data.id');
    }

    // =================================================================
    // Buka + snapshot (§44)
    // =================================================================

    public function test_buka_rekonsiliasi_menyimpan_snapshot_saldo_buku(): void
    {
        $this->fundBank(3000000);

        $id = $this->openRecon(3000000);

        $recon = FinanceReconciliation::find($id);
        $this->assertEquals(3000000.0, (float) $recon->system_balance);
        $this->assertEquals(3000000.0, (float) $recon->bank_balance);
        $this->assertEquals(0.0, (float) $recon->difference);
        $this->assertSame(FinanceReconciliation::STATUS_IN_PROGRESS, $recon->status);
    }

    public function test_tambah_baris_cocok_dan_belum_cocok(): void
    {
        $this->fundBank(0);
        $depositId = $this->recordDeposit(1000000);
        $reconId = $this->openRecon(1000000);

        $this->actingAsRole('finance-officer');

        // Baris cocok ke transaksi sistem.
        $this->postJson("/api/v1/finance/reconciliations/{$reconId}/items", [
            'transaction_date' => '2026-09-15',
            'description' => 'Setoran tunai',
            'bank_amount' => 1000000,
            'bank_transaction_id' => $depositId,
        ])->assertCreated()->assertJsonPath('data.match_status', FinanceReconciliationItem::MATCH_MATCHED);

        // Baris tanpa pasangan → unmatched.
        $this->postJson("/api/v1/finance/reconciliations/{$reconId}/items", [
            'description' => 'Biaya admin bank',
            'bank_amount' => -25000,
        ])->assertCreated()->assertJsonPath('data.match_status', FinanceReconciliationItem::MATCH_UNMATCHED);

        $this->assertCount(2, FinanceReconciliationItem::query()->where('reconciliation_id', $reconId)->get());
    }

    public function test_ubah_status_pencocokan_baris(): void
    {
        $this->fundBank(0);
        $depositId = $this->recordDeposit(1000000);
        $reconId = $this->openRecon(1000000);

        $this->actingAsRole('finance-officer');
        $itemId = (int) $this->postJson("/api/v1/finance/reconciliations/{$reconId}/items", [
            'description' => 'Setoran tunai',
            'bank_amount' => 1000000,
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/finance/reconciliations/{$reconId}/items/{$itemId}", [
            'match_status' => FinanceReconciliationItem::MATCH_MATCHED,
            'bank_transaction_id' => $depositId,
        ])->assertOk()->assertJsonPath('data.match_status', FinanceReconciliationItem::MATCH_MATCHED);

        $this->assertSame($depositId, (int) FinanceReconciliationItem::find($itemId)->bank_transaction_id);
    }

    public function test_baris_milik_rekonsiliasi_lain_ditolak(): void
    {
        $this->fundBank(3000000);
        $reconA = $this->openRecon(3000000, '2026-09-30');
        $reconB = $this->openRecon(3000000, '2026-10-31');

        $this->actingAsRole('finance-officer');
        $itemId = (int) $this->postJson("/api/v1/finance/reconciliations/{$reconA}/items", [
            'bank_amount' => 100000,
        ])->assertCreated()->json('data.id');

        // Coba ubah item reconA lewat URL reconB.
        $this->patchJson("/api/v1/finance/reconciliations/{$reconB}/items/{$itemId}", [
            'match_status' => FinanceReconciliationItem::MATCH_IGNORED,
        ])->assertStatus(422)->assertJsonValidationErrors('reconciliation_item');
    }

    // =================================================================
    // Finalize (§45)
    // =================================================================

    public function test_finalize_selisih_nol_menjadi_reconciled(): void
    {
        $this->fundBank(3000000);
        $id = $this->openRecon(3000000);

        $this->actingAsRole('finance-officer');
        $this->postJson("/api/v1/finance/reconciliations/{$id}/finalize")->assertOk()
            ->assertJsonPath('data.status', FinanceReconciliation::STATUS_RECONCILED)
            ->assertJsonPath('data.difference', 0);

        $recon = FinanceReconciliation::find($id);
        $this->assertNotNull($recon->reconciled_at);
        $this->assertNotNull($recon->reconciled_by);
    }

    public function test_finalize_selisih_menjadi_discrepancy(): void
    {
        $this->fundBank(3000000);
        $id = $this->openRecon(3500000);

        $this->actingAsRole('finance-officer');
        $this->postJson("/api/v1/finance/reconciliations/{$id}/finalize")->assertOk()
            ->assertJsonPath('data.status', FinanceReconciliation::STATUS_DISCREPANCY)
            ->assertJsonPath('data.difference', 500000);
    }

    public function test_rekonsiliasi_final_tidak_bisa_diubah(): void
    {
        $this->fundBank(3000000);
        $id = $this->openRecon(3000000);

        $this->actingAsRole('finance-officer');
        $this->postJson("/api/v1/finance/reconciliations/{$id}/finalize")->assertOk();

        // Tambah baris setelah final → 422.
        $this->postJson("/api/v1/finance/reconciliations/{$id}/items", ['bank_amount' => 100000])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        // Finalize ulang → 422.
        $this->postJson("/api/v1/finance/reconciliations/{$id}/finalize")
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    // =================================================================
    // Guard permission (§3)
    // =================================================================

    public function test_auditor_baca_saja(): void
    {
        $this->fundBank(3000000);
        $id = $this->openRecon(3000000);

        $this->actingAsRole('auditor');
        $this->getJson('/api/v1/finance/reconciliations')->assertOk();
        $this->getJson("/api/v1/finance/reconciliations/{$id}")->assertOk();
        $this->postJson('/api/v1/finance/reconciliations', [
            'bank_account_id' => $this->bank()->id,
            'statement_date' => '2026-09-30',
            'bank_balance' => 3000000,
        ])->assertStatus(403);
    }
}
