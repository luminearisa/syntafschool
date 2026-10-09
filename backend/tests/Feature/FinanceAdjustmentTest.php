<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceAdjustment;
use App\Models\FinanceCashAccount;
use App\Models\FinancialTransaction;
use App\Models\User;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Adjustment / koreksi (§51) lewat HTTP.
 *
 * Yang diverifikasi: posting membuat jurnal BARU (tidak mengedit transaksi lama)
 * Dr account / Cr contra (increase) atau sebaliknya (decrease); akun konkret
 * kas/bank opsional ikut menggerakkan saldo dan COA-nya harus cocok; pemisahan
 * peminta (adjustment.create) vs penyetuju (adjustment.approve §3) — peminta
 * tidak boleh menyetujui sendiri; contra = account ditolak; post hanya untuk
 * yang sudah disetujui.
 */
class FinanceAdjustmentTest extends TestCase
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

    private function fundCash(float $amount, string $code = 'KAS-SEKOLAH'): void
    {
        $this->cash($code)->update(['current_balance' => $amount]);
    }

    /** @return array<string, array{debit: float, credit: float}> */
    private function sides(FinancialTransaction $transaction): array
    {
        return $transaction->lines->mapWithKeys(fn ($line): array => [
            $line->account->code => ['debit' => (float) $line->debit, 'credit' => (float) $line->credit],
        ])->all();
    }

    private function journalFor(int $adjustmentId): ?FinancialTransaction
    {
        return FinancialTransaction::query()
            ->with('lines.account')
            ->where('reference_type', FinanceAdjustment::class)
            ->where('reference_id', $adjustmentId)
            ->where('status', FinancialTransaction::STATUS_POSTED)
            ->first();
    }

    /** Buat + ajukan adjustment sebagai finance-officer, kembalikan id. */
    private function submitAdjustment(array $overrides = []): int
    {
        $this->actingAsRole('finance-officer');

        $payload = array_merge([
            'account_id' => $this->account('1300'),
            'contra_account_id' => $this->account('4500'),
            'direction' => FinanceAdjustment::DIRECTION_INCREASE,
            'amount' => 500000,
            'adjustment_date' => '2026-09-20',
            'reason' => 'Koreksi piutang santri',
            'submit' => true,
        ], $overrides);

        return (int) $this->postJson('/api/v1/finance/adjustments', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', FinanceAdjustment::STATUS_SUBMITTED)
            ->json('data.id');
    }

    /** Setujui + posting sebagai finance-manager. */
    private function approveAndPost(int $id): void
    {
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/adjustments/{$id}/approve")->assertOk()
            ->assertJsonPath('data.status', FinanceAdjustment::STATUS_APPROVED);
        $this->postJson("/api/v1/finance/adjustments/{$id}/post")->assertOk()
            ->assertJsonPath('data.status', FinanceAdjustment::STATUS_POSTED);
    }

    // =================================================================
    // Posting jurnal koreksi (§51)
    // =================================================================

    public function test_increase_memposting_dr_account_cr_contra(): void
    {
        $id = $this->submitAdjustment();
        $this->approveAndPost($id);

        $adjustment = FinanceAdjustment::find($id);
        $this->assertNotNull($adjustment->finance_transaction_id);

        // Jurnal BARU: Dr Piutang (1300), Cr Pendapatan lain (4500).
        $journal = $this->journalFor($id);
        $this->assertNotNull($journal);
        $this->assertSame(FinancialTransaction::TYPE_ADJUSTMENT, $journal->type);
        $sides = $this->sides($journal);
        $this->assertEquals(500000.0, $sides['1300']['debit']);
        $this->assertEquals(500000.0, $sides['4500']['credit']);
    }

    public function test_decrease_memposting_cr_account_dr_contra(): void
    {
        $id = $this->submitAdjustment([
            'direction' => FinanceAdjustment::DIRECTION_DECREASE,
            'reason' => 'Koreksi pengurangan piutang',
        ]);
        $this->approveAndPost($id);

        $journal = $this->journalFor($id);
        $this->assertNotNull($journal);
        $sides = $this->sides($journal);
        $this->assertEquals(500000.0, $sides['1300']['credit']);
        $this->assertEquals(500000.0, $sides['4500']['debit']);
    }

    public function test_adjustment_kas_konkret_menggerakkan_saldo(): void
    {
        $this->fundCash(1000000);

        $id = $this->submitAdjustment([
            'account_id' => $this->account('1100'),
            'contra_account_id' => $this->account('4500'),
            'cash_account_id' => $this->cash()->id,
            'direction' => FinanceAdjustment::DIRECTION_INCREASE,
            'amount' => 200000,
            'reason' => 'Koreksi kelebihan kas',
        ]);
        $this->approveAndPost($id);

        // Saldo kas konkret ikut naik sejalan jurnal.
        $this->assertEquals(1200000.0, (float) $this->cash()->fresh()->current_balance);

        $journal = $this->journalFor($id);
        $sides = $this->sides($journal);
        $this->assertEquals(200000.0, $sides['1100']['debit']);
        $this->assertEquals(200000.0, $sides['4500']['credit']);
    }

    public function test_kas_konkret_dengan_coa_tidak_cocok_ditolak(): void
    {
        $this->actingAsRole('finance-officer');

        // account_id 1300 (Piutang) tapi kas konkret COA-nya 1100 → tidak cocok.
        $this->postJson('/api/v1/finance/adjustments', [
            'account_id' => $this->account('1300'),
            'contra_account_id' => $this->account('4500'),
            'cash_account_id' => $this->cash()->id,
            'direction' => FinanceAdjustment::DIRECTION_INCREASE,
            'amount' => 200000,
            'reason' => 'Koreksi salah akun',
        ])->assertStatus(422)->assertJsonValidationErrors('cash_account_id');
    }

    public function test_contra_sama_dengan_account_ditolak(): void
    {
        $this->actingAsRole('finance-officer');

        $this->postJson('/api/v1/finance/adjustments', [
            'account_id' => $this->account('1300'),
            'contra_account_id' => $this->account('1300'),
            'direction' => FinanceAdjustment::DIRECTION_INCREASE,
            'amount' => 100000,
            'reason' => 'Koreksi',
        ])->assertStatus(422)->assertJsonValidationErrors('contra_account_id');
    }

    public function test_post_hanya_untuk_adjustment_yang_sudah_disetujui(): void
    {
        $id = $this->submitAdjustment();

        // Langsung post tanpa approve → 422 status.
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/adjustments/{$id}/post")
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame(FinanceAdjustment::STATUS_SUBMITTED, FinanceAdjustment::find($id)->status);
        $this->assertNull($this->journalFor($id));
    }

    // =================================================================
    // Pemisahan peminta vs penyetuju (§3)
    // =================================================================

    public function test_peminta_tidak_boleh_menyetujui_adjustment_sendiri(): void
    {
        // finance-admin punya create + approve; diminta oleh admin sendiri.
        $this->actingAsRole('finance-admin');
        $id = (int) $this->postJson('/api/v1/finance/adjustments', [
            'account_id' => $this->account('1300'),
            'contra_account_id' => $this->account('4500'),
            'direction' => FinanceAdjustment::DIRECTION_INCREASE,
            'amount' => 300000,
            'reason' => 'Koreksi oleh admin',
            'submit' => true,
        ])->assertCreated()->json('data.id');

        // Admin mencoba menyetujui adjustmennya sendiri → 422.
        $this->postJson("/api/v1/finance/adjustments/{$id}/approve")
            ->assertStatus(422)->assertJsonValidationErrors('approved_by');

        $this->assertSame(FinanceAdjustment::STATUS_SUBMITTED, FinanceAdjustment::find($id)->status);
    }

    public function test_officer_tidak_bisa_menyetujui_atau_memposting(): void
    {
        $id = $this->submitAdjustment();

        // officer hanya punya adjustment.create, bukan approve.
        $this->actingAsRole('finance-officer');
        $this->postJson("/api/v1/finance/adjustments/{$id}/approve")->assertStatus(403);
        $this->postJson("/api/v1/finance/adjustments/{$id}/post")->assertStatus(403);
    }

    public function test_auditor_baca_saja(): void
    {
        $id = $this->submitAdjustment();

        $this->actingAsRole('auditor');
        $this->getJson('/api/v1/finance/adjustments')->assertOk();
        $this->getJson("/api/v1/finance/adjustments/{$id}")->assertOk();
        $this->postJson("/api/v1/finance/adjustments/{$id}/approve")->assertStatus(403);
    }
}
