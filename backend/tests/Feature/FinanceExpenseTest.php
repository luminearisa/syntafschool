<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceBudget;
use App\Models\FinanceCashAccount;
use App\Models\FinanceCostCenter;
use App\Models\FinanceExpense;
use App\Models\FinanceExpenseRequest;
use App\Models\FinancialTransaction;
use App\Models\User;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengeluaran (§30–§33) lewat HTTP: pengajuan → approval bertingkat → pembayaran.
 *
 * Yang diverifikasi: approval satu tingkat untuk nominal kecil dan bertingkat
 * untuk nominal besar (§32, threshold dari Settings); pengaju tidak boleh
 * menyetujui/membayar sendiri (§3); pembayaran memposting jurnal Dr beban /
 * Cr kas, mengurangi saldo kas, membuat expense terealisasi, dan menaikkan
 * realisasi anggaran (§8); saldo kurang ditolak; guard permission.
 */
class FinanceExpenseTest extends TestCase
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

    private function costCenter(string $code = 'CC-SEKOLAH'): FinanceCostCenter
    {
        return FinanceCostCenter::query()->where('code', $code)->firstOrFail();
    }

    private function cash(string $code = 'KAS-SEKOLAH'): FinanceCashAccount
    {
        return FinanceCashAccount::query()->where('code', $code)->firstOrFail();
    }

    private function account(string $code): int
    {
        return (int) FinanceAccount::query()->where('code', $code)->value('id');
    }

    /** Isi saldo kas supaya pengeluaran tunai tidak ditolak karena saldo negatif. */
    private function fundCash(float $amount, string $code = 'KAS-SEKOLAH'): void
    {
        $this->cash($code)->update(['current_balance' => $amount]);
    }

    private function journalFor(string $refType, int $refId): FinancialTransaction
    {
        return FinancialTransaction::query()
            ->with('lines.account')
            ->where('reference_type', $refType)
            ->where('reference_id', $refId)
            ->where('status', FinancialTransaction::STATUS_POSTED)
            ->firstOrFail();
    }

    /** @return array<string, array{debit: float, credit: float}> keyed by account code */
    private function sides(FinancialTransaction $transaction): array
    {
        return $transaction->lines->mapWithKeys(fn ($line): array => [
            $line->account->code => ['debit' => (float) $line->debit, 'credit' => (float) $line->credit],
        ])->all();
    }

    /** Buat pengajuan (submit=true) sebagai kepala-unit, kembalikan id. */
    private function createSubmitted(float $amount, string $accountCode = '5200'): int
    {
        $this->actingAsRole('kepala-unit');

        return (int) $this->postJson('/api/v1/finance/expense-requests', [
            'cost_center_id' => $this->costCenter()->id,
            'account_id' => $this->account($accountCode),
            'amount' => $amount,
            'description' => 'Belanja operasional unit',
            'expense_date' => '2026-09-10',
            'submit' => true,
        ])->assertCreated()->json('data.id');
    }

    // =================================================================
    // Pengajuan & approval (§30, §32)
    // =================================================================

    public function test_pengajuan_dibuat_dan_langsung_diajukan(): void
    {
        $id = $this->createSubmitted(1500000);

        $request = FinanceExpenseRequest::find($id);
        $this->assertSame(FinanceExpenseRequest::STATUS_SUBMITTED, $request->status);
        $this->assertNotEmpty($request->request_number);
        $this->assertSame(1, (int) $request->required_approval_levels);
    }

    public function test_approval_satu_tingkat_untuk_nominal_kecil(): void
    {
        $id = $this->createSubmitted(1500000);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/expense-requests/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', FinanceExpenseRequest::STATUS_APPROVED)
            ->assertJsonPath('data.current_approval_level', 1);
    }

    public function test_approval_bertingkat_untuk_nominal_besar(): void
    {
        // Aktifkan dua langkah approval untuk nominal di atas ambang (§32).
        $this->actingAsRole('finance-admin');
        $this->putJson('/api/v1/finance/settings', ['expense_approval_steps' => 2])->assertOk();

        $id = $this->createSubmitted(7500000);
        $this->assertSame(2, (int) FinanceExpenseRequest::find($id)->required_approval_levels);

        // Tingkat 1 → masih under_review.
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/expense-requests/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', FinanceExpenseRequest::STATUS_UNDER_REVIEW)
            ->assertJsonPath('data.current_approval_level', 1);

        // Tingkat 2 (approver berbeda) → approved.
        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/expense-requests/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', FinanceExpenseRequest::STATUS_APPROVED)
            ->assertJsonPath('data.current_approval_level', 2);
    }

    public function test_pengaju_tidak_boleh_menyetujui_pengajuannya_sendiri(): void
    {
        // finance-admin punya create + approve; mengajukan lalu menyetujui sendiri.
        $this->actingAsRole('finance-admin');
        $id = (int) $this->postJson('/api/v1/finance/expense-requests', [
            'cost_center_id' => $this->costCenter()->id,
            'account_id' => $this->account('5200'),
            'amount' => 1000000,
            'description' => 'Uji anti self-approval',
            'expense_date' => '2026-09-10',
            'submit' => true,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/finance/expense-requests/{$id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('approved_by');

        $this->assertSame(FinanceExpenseRequest::STATUS_SUBMITTED, FinanceExpenseRequest::find($id)->status);
    }

    public function test_penolakan_wajib_disertai_alasan(): void
    {
        $id = $this->createSubmitted(1500000);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/expense-requests/{$id}/reject", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reject_reason');

        $this->postJson("/api/v1/finance/expense-requests/{$id}/reject", ['reject_reason' => 'Tidak sesuai anggaran'])
            ->assertOk()
            ->assertJsonPath('data.status', FinanceExpenseRequest::STATUS_REJECTED);
    }

    // =================================================================
    // Pembayaran (§30, §31) + realisasi anggaran (§8)
    // =================================================================

    public function test_pembayaran_memposting_jurnal_dan_mengurangi_saldo_kas(): void
    {
        $this->fundCash(10000000);
        $id = $this->createSubmitted(1500000);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/expense-requests/{$id}/approve")->assertOk();

        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/expense-requests/{$id}/pay", [
            'cash_account_id' => $this->cash()->id,
        ])->assertCreated();

        // Jurnal: Dr Beban Utilitas (5200), Cr Kas (1100) sebesar 1.500.000.
        $journal = $this->journalFor(FinanceExpenseRequest::class, $id);
        $sides = $this->sides($journal);
        $this->assertEquals(1500000.0, $sides['5200']['debit']);
        $this->assertEquals(1500000.0, $sides['1100']['credit']);
        $this->assertSame(FinancialTransaction::TYPE_EXPENSE, $journal->type);

        // Saldo kas berkurang.
        $this->assertEquals(8500000.0, (float) $this->cash()->fresh()->current_balance);

        // Expense terealisasi dibuat, request jadi paid.
        $expense = FinanceExpense::query()->where('expense_request_id', $id)->firstOrFail();
        $this->assertSame(FinanceExpense::STATUS_PAID, $expense->status);
        $this->assertEquals(1500000.0, (float) $expense->amount);
        $this->assertSame(FinanceExpenseRequest::STATUS_PAID, FinanceExpenseRequest::find($id)->status);
    }

    public function test_pembayaran_menaikkan_realisasi_anggaran(): void
    {
        $this->fundCash(10000000);

        $budget = FinanceBudget::create([
            'fiscal_year' => 2026,
            'account_id' => $this->account('5200'),
            'cost_center_id' => $this->costCenter()->id,
            'period' => null,
            'budget_amount' => 5000000,
            'used_amount' => 0,
            'status' => FinanceBudget::STATUS_ACTIVE,
        ]);

        $id = $this->createSubmitted(1500000);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/expense-requests/{$id}/approve")->assertOk();

        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/expense-requests/{$id}/pay", ['cash_account_id' => $this->cash()->id])
            ->assertCreated();

        $this->assertEquals(1500000.0, (float) $budget->fresh()->used_amount);
    }

    public function test_pembayaran_wajib_punya_akun_sumber_dana(): void
    {
        $id = $this->createSubmitted(1500000);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/expense-requests/{$id}/approve")->assertOk();

        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/expense-requests/{$id}/pay", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cash_account_id');

        $this->assertSame(FinanceExpenseRequest::STATUS_APPROVED, FinanceExpenseRequest::find($id)->status);
    }

    public function test_pembayaran_ditolak_bila_saldo_kas_kurang(): void
    {
        $this->fundCash(500000);
        $id = $this->createSubmitted(1500000);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/expense-requests/{$id}/approve")->assertOk();

        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/expense-requests/{$id}/pay", ['cash_account_id' => $this->cash()->id])
            ->assertStatus(422);

        // Rollback: request tetap approved, tidak ada expense, saldo utuh.
        $this->assertSame(FinanceExpenseRequest::STATUS_APPROVED, FinanceExpenseRequest::find($id)->status);
        $this->assertSame(0, FinanceExpense::query()->where('expense_request_id', $id)->count());
        $this->assertEquals(500000.0, (float) $this->cash()->fresh()->current_balance);
    }

    public function test_pembayar_tidak_boleh_pengaju_sendiri(): void
    {
        $this->fundCash(10000000);

        // finance-admin (punya create + pay) mengajukan; finance-manager menyetujui;
        // finance-admin mencoba membayar pengajuannya sendiri → ditolak guard (§3).
        $this->actingAsRole('finance-admin');
        $id = (int) $this->postJson('/api/v1/finance/expense-requests', [
            'cost_center_id' => $this->costCenter()->id,
            'account_id' => $this->account('5200'),
            'amount' => 1500000,
            'description' => 'Uji anti self-pay',
            'expense_date' => '2026-09-10',
            'submit' => true,
        ])->assertCreated()->json('data.id');

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/expense-requests/{$id}/approve")->assertOk();

        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/expense-requests/{$id}/pay", ['cash_account_id' => $this->cash()->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('paid_by');

        $this->assertSame(FinanceExpenseRequest::STATUS_APPROVED, FinanceExpenseRequest::find($id)->status);
        $this->assertEquals(10000000.0, (float) $this->cash()->fresh()->current_balance);
    }

    // =================================================================
    // Guard permission (§3)
    // =================================================================

    public function test_officer_boleh_mengajukan_tapi_tidak_menyetujui_atau_membayar(): void
    {
        $this->actingAsRole('finance-officer');
        $id = (int) $this->postJson('/api/v1/finance/expense-requests', [
            'cost_center_id' => $this->costCenter()->id,
            'account_id' => $this->account('5200'),
            'amount' => 1000000,
            'description' => 'Officer mengajukan',
            'expense_date' => '2026-09-10',
            'submit' => true,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/finance/expense-requests/{$id}/approve")->assertStatus(403);
        $this->postJson("/api/v1/finance/expense-requests/{$id}/pay", ['cash_account_id' => $this->cash()->id])->assertStatus(403);
    }

    public function test_manager_tidak_boleh_mencatat_pengajuan(): void
    {
        $this->actingAsRole('finance-manager');
        $this->postJson('/api/v1/finance/expense-requests', [
            'cost_center_id' => $this->costCenter()->id,
            'account_id' => $this->account('5200'),
            'amount' => 1000000,
            'description' => 'Manager mencoba mencatat',
            'expense_date' => '2026-09-10',
        ])->assertStatus(403);
    }

    public function test_auditor_baca_saja(): void
    {
        $id = $this->createSubmitted(1500000);

        $this->actingAsRole('auditor');
        $this->getJson('/api/v1/finance/expense-requests')->assertOk();
        $this->getJson("/api/v1/finance/expense-requests/{$id}")->assertOk();
        $this->postJson('/api/v1/finance/expense-requests', [
            'cost_center_id' => $this->costCenter()->id,
            'account_id' => $this->account('5200'),
            'amount' => 1000,
            'description' => 'x',
        ])->assertStatus(403);
    }
}
