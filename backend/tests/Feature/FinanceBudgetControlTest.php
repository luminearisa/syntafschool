<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceBudget;
use App\Models\FinanceCashAccount;
use App\Models\FinanceCostCenter;
use App\Models\FinanceExpenseRequest;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\FinancialTransaction;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\FinanceBudgetService;
use App\Services\Finance\FinanceSettingsService;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Budget vs Actual (§9) dan budget control (§10).
 *
 * §9 melarang actual diambil dari data invoice/payment mentah: realisasi harus
 * berasal dari jurnal yang sudah diposting. §10 melarang kebalikannya — sebuah
 * pengajuan yang belum dibayar tidak boleh mengurangi actual, ia hanya boleh
 * menjadi komitmen. Dua larangan itu mengunci satu pertanyaan yang mudah salah
 * jawab: angka mana yang bergerak kapan. Test ini menjaga jawabannya:
 * pembayaran → actual, pengajuan → committed, pembalikan → actual kembali nol,
 * dan `used_amount` tetap cache yang selisihnya dilaporkan, bukan disembunyikan.
 *
 * Kebijakan kelebihan anggaran (none|warning|block|allow_with_approval) ada di
 * konfigurasi; hasilnya dibekukan pada pengajuan saat diperiksa sehingga
 * perubahan kebijakan di tengah alur tidak mengubah aturan yang berlaku atasnya.
 */
class FinanceBudgetControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceConfigSeeder::class);
    }

    private function budgets(): FinanceBudgetService
    {
        return app(FinanceBudgetService::class);
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

    private function student(string $nis): Student
    {
        return Student::query()->firstOrCreate(
            ['nis' => $nis],
            ['full_name' => 'Santri '.$nis, 'is_active' => true],
        );
    }

    /** Tagihan lain → invoice terbit → jurnal akrual posted mengikuti §7/§12. */
    private function issuedInvoice(string $nis, string $feeTypeCode, float $amount): FinanceInvoice
    {
        $this->actingAsRole('finance-admin');

        $billingId = $this->postJson('/api/v1/finance/billings/other/generate', [
            'fee_type_id' => FinanceFeeType::query()->where('code', $feeTypeCode)->value('id'),
            'period_key' => '2026-09',
            'amount' => $amount,
            'student_ids' => [$this->student($nis)->id],
            'cost_center_id' => $this->costCenter()->id,
        ])->assertOk()->json('data.billings.0');

        return FinanceInvoice::findOrFail(
            (int) $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated()->json('data.id')
        );
    }

    private function accrualJournal(FinanceInvoice $invoice): FinancialTransaction
    {
        return FinancialTransaction::query()
            ->where('reference_type', FinanceInvoice::class)
            ->where('reference_id', $invoice->id)
            ->where('status', FinancialTransaction::STATUS_POSTED)
            ->firstOrFail();
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

    private function fundCash(float $amount): void
    {
        $this->cash()->update(['current_balance' => $amount]);
    }

    private function control(string $mode): void
    {
        app(FinanceSettingsService::class)->update(['budget_control' => $mode]);
    }

    /** Anggaran aktif 2026 pada (akun, cost center). */
    private function budget(float $amount, string $accountCode = '5200', string $costCenterCode = 'CC-SEKOLAH'): FinanceBudget
    {
        return FinanceBudget::create([
            'fiscal_year' => 2026,
            'account_id' => $this->account($accountCode),
            'cost_center_id' => $this->costCenter($costCenterCode)->id,
            'budget_amount' => $amount,
            'used_amount' => 0,
            'status' => FinanceBudget::STATUS_ACTIVE,
        ]);
    }

    /** Baris Budget vs Actual (§9) untuk satu anggaran. */
    private function row(FinanceBudget $budget): array
    {
        $rows = $this->budgets()->positions(['fiscal_year' => 2026])['rows'];
        $match = collect($rows)->firstWhere('id', $budget->id);

        $this->assertNotNull($match, 'Anggaran berlaku harus tampil di Budget vs Actual.');

        return $match;
    }

    /**
     * Pengajuan (opsional sampai dibayar). Return id + model segarnya.
     */
    private function submitRequest(float $amount, string $accountCode = '5200', string $costCenterCode = 'CC-SEKOLAH'): int
    {
        $this->actingAsRole('kepala-unit');

        return (int) $this->postJson('/api/v1/finance/expense-requests', [
            'cost_center_id' => $this->costCenter($costCenterCode)->id,
            'account_id' => $this->account($accountCode),
            'amount' => $amount,
            'description' => 'Belanja diuji terhadap anggaran',
            'expense_date' => '2026-09-10',
            'submit' => true,
        ])->assertCreated()->json('data.id');
    }

    private function payRequest(int $id): void
    {
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/expense-requests/{$id}/approve")->assertOk();

        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/expense-requests/{$id}/pay", ['cash_account_id' => $this->cash()->id])
            ->assertCreated();
    }

    private function journalForExpense(int $requestId): FinancialTransaction
    {
        return FinancialTransaction::query()
            ->where('reference_type', FinanceExpenseRequest::class)
            ->where('reference_id', $requestId)
            ->where('status', FinancialTransaction::STATUS_POSTED)
            ->firstOrFail();
    }

    // =================================================================
    // §9 — Actual dari buku besar
    // =================================================================

    public function test_realisasi_budget_vs_actual_berasal_dari_jurnal_posted(): void
    {
        $this->fundCash(20000000);
        $budget = $this->budget(10000000);

        $id = $this->submitRequest(3000000);
        $this->payRequest($id);

        $row = $this->row($budget->fresh());
        $this->assertEquals(3000000.0, $row['actual']);
        $this->assertEquals(7000000.0, $row['variance']);
        $this->assertEquals(30.0, $row['utilization']);
        $this->assertEquals(7000000.0, $row['available']);
        $this->assertFalse($row['over_budget']);
        // Cache dan buku besar sepakat → tidak ada yang perlu diselidiki.
        $this->assertTrue($row['in_agreement']);
        $this->assertEquals(0.0, $row['cached_difference']);
    }

    public function test_jurnal_pembalik_menghapus_realisasi_dari_budget_vs_actual(): void
    {
        // Anggaran pendapatan (§8 membolehkan budget per akun, termasuk income):
        // realisasinya bertambah di sisi kredit, jadi dihitung menurut
        // `normal_balance` akun, bukan `debit − credit` mentah (§9).
        $budget = $this->budget(10000000, '4500');

        // SERAGAM dipetakan ke COA 4500 → terbit invoice → jurnal akrual posted.
        $invoice = $this->issuedInvoice('BDC001', 'SERAGAM', 3000000);
        $journal = $this->accrualJournal($invoice);

        $this->assertEquals(3000000.0, $this->row($budget->fresh())['actual']);

        // Jurnal posted tidak diedit: dibalik lewat jurnal tandingan (§51), dan
        // keduanya saling meniadakan di Budget vs Actual (§9).
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/journals/{$journal->id}/reverse", ['reason' => 'Tagihan salah terbit'])
            ->assertCreated();

        $row = $this->row($budget->fresh());
        $this->assertEquals(0.0, $row['actual']);
        $this->assertEquals(10000000.0, $row['available']);
        // Anggaran pendapatan tidak pernah disentuh `used_amount` (hanya expense
        // yang menaikkannya) → cache dan buku besar tetap sepakat di nol.
        $this->assertTrue($row['in_agreement']);
    }

    public function test_realisasi_tidak_menyeberang_cost_center_lain(): void
    {
        $this->fundCash(20000000);
        $budget = $this->budget(10000000);

        // Pengeluaran akun sama tapi unit berbeda: bukan realisasi anggaran ini.
        $id = $this->submitRequest(3000000, '5200', 'CC-PESANTREN');
        $this->payRequest($id);

        $row = $this->row($budget->fresh());
        $this->assertEquals(0.0, $row['actual']);
        $this->assertEquals(10000000.0, $row['available']);
    }

    public function test_totals_budget_vs_actual_menurut_filter_tahun(): void
    {
        $this->fundCash(30000000);
        $this->budget(10000000, '5200');
        $this->budget(4000000, '5600');

        $this->payRequest($this->submitRequest(3000000, '5200'));
        $this->payRequest($this->submitRequest(5000000, '5600'));

        $totals = $this->budgets()->positions(['fiscal_year' => 2026])['totals'];

        $this->assertEquals(14000000.0, $totals['budget']);
        $this->assertEquals(8000000.0, $totals['actual']);
        $this->assertEquals(6000000.0, $totals['variance']);
        $this->assertEquals(57.14, $totals['utilization']);
        // Beban 5jt dari anggaran 4jt: over budget harus terhitung, bukan diam.
        $this->assertSame(1, $totals['over_budget']);
        $this->assertEquals(0.0, $totals['committed']);
    }

    // =================================================================
    // §10 — Committed, bukan Actual
    // =================================================================

    public function test_pengajuan_yang_diajukan_menjadi_komitmen_bukan_realisasi(): void
    {
        $budget = $this->budget(10000000);

        $this->submitRequest(4000000);

        $row = $this->row($budget->fresh());
        $this->assertEquals(0.0, $row['actual'], 'Actual hanya boleh bergerak oleh jurnal.');
        $this->assertEquals(4000000.0, $row['committed']);
        $this->assertEquals(6000000.0, $row['available']);
    }

    public function test_draft_dan_pengajuan_ditolak_tidak_memakan_komitmen(): void
    {
        $budget = $this->budget(10000000);

        $this->actingAsRole('kepala-unit');
        $draftId = (int) $this->postJson('/api/v1/finance/expense-requests', [
            'cost_center_id' => $this->costCenter()->id,
            'account_id' => $this->account('5200'),
            'amount' => 4000000,
            'description' => 'Draft belum diajukan',
            'expense_date' => '2026-09-10',
        ])->assertCreated()->json('data.id');

        $this->assertEquals(0.0, $this->row($budget->fresh())['committed']);

        $this->postJson("/api/v1/finance/expense-requests/{$draftId}/submit")->assertOk();
        $this->assertEquals(4000000.0, $this->row($budget->fresh())['committed']);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/expense-requests/{$draftId}/reject", ['reject_reason' => 'Tidak jadi'])
            ->assertOk();

        // Ditolak → komitmen dilepas, tanpa menyentuh actual.
        $row = $this->row($budget->fresh());
        $this->assertEquals(0.0, $row['committed']);
        $this->assertEquals(10000000.0, $row['available']);
        $this->assertEquals(0.0, $row['actual']);
    }

    public function test_setelah_dibayar_komitmen_berubah_menjadi_realisasi(): void
    {
        $this->fundCash(20000000);
        $budget = $this->budget(10000000);

        $id = $this->submitRequest(4000000);
        $this->payRequest($id);

        // Tidak dihitung dua kali: status `paid` keluar dari daftar komitmen.
        $row = $this->row($budget->fresh());
        $this->assertEquals(0.0, $row['committed']);
        $this->assertEquals(4000000.0, $row['actual']);
        $this->assertEquals(6000000.0, $row['available']);
    }

    // =================================================================
    // §10 — Kebijakan BLOCK / WARNING / ALLOW_WITH_APPROVAL
    // =================================================================

    public function test_kebijakan_block_menolak_pengajuan_yang_melebihi_sisa(): void
    {
        $this->control(FinanceBudgetService::CONTROL_BLOCK);
        $budget = $this->budget(10000000);

        $this->actingAsRole('kepala-unit');
        $resp = $this->postJson('/api/v1/finance/expense-requests', [
            'cost_center_id' => $this->costCenter()->id,
            'account_id' => $this->account('5200'),
            'amount' => 12000000,
            'description' => 'Melebihi anggaran',
            'expense_date' => '2026-09-10',
            'submit' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertStringContainsString('melebihi sisa anggaran', $resp->json('errors.amount.0'));

        // Rollback penuh: row tidak lahir sebagai submitted, komitmen nol.
        $this->assertSame(0, FinanceExpenseRequest::query()->where('status', FinanceExpenseRequest::STATUS_SUBMITTED)->count());
        $this->assertEquals(0.0, $this->row($budget->fresh())['committed']);
    }

    public function test_block_masih_mengizinkan_nominal_di_dalam_sisa(): void
    {
        $this->control(FinanceBudgetService::CONTROL_BLOCK);
        $budget = $this->budget(10000000);

        $id = $this->submitRequest(9000000);

        $request = FinanceExpenseRequest::find($id);
        $this->assertSame(FinanceExpenseRequest::STATUS_SUBMITTED, $request->status);
        $this->assertSame(FinanceBudgetService::CONTROL_BLOCK, $request->budget_control);
        $this->assertEquals(0.0, (float) $request->budget_shortfall);
        $this->assertFalse($request->overranBudget());
    }

    public function test_kebijakan_warning_meloloskan_tetapi_membekukan_kelebihan(): void
    {
        $this->control(FinanceBudgetService::CONTROL_WARNING);
        $this->budget(10000000);

        $id = $this->submitRequest(12000000);

        $request = FinanceExpenseRequest::find($id);
        $this->assertSame(FinanceExpenseRequest::STATUS_SUBMITTED, $request->status);
        $this->assertSame(FinanceBudgetService::CONTROL_WARNING, $request->budget_control);
        $this->assertEquals(2000000.0, (float) $request->budget_shortfall);
        $this->assertTrue($request->overranBudget());

        // Warning tidak menambah syarat persetujuan: manager biasa cukup.
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/expense-requests/{$id}/approve")
            ->assertOk()->assertJsonPath('data.status', FinanceExpenseRequest::STATUS_APPROVED);
        $this->assertNull($request->fresh()->budget_override_by);
    }

    public function test_allow_with_approval_menuntut_pemegang_hak_anggaran(): void
    {
        $this->control(FinanceBudgetService::CONTROL_APPROVAL);
        $this->budget(10000000);

        $id = $this->submitRequest(12000000);
        $this->assertSame(FinanceBudgetService::CONTROL_APPROVAL, FinanceExpenseRequest::find($id)->budget_control);

        // Penyetuju pengeluaran yang bukan pemegang hak anggaran: ditolak.
        $outsider = $this->actingAsRole('finance-officer');
        $outsider->givePermissionTo('finance.expense.approve');
        $this->postJson("/api/v1/finance/expense-requests/{$id}/approve")
            ->assertStatus(422)->assertJsonValidationErrors('budget_override_by');

        $this->assertSame(FinanceExpenseRequest::STATUS_SUBMITTED, FinanceExpenseRequest::find($id)->status);

        // Pemegang hak anggaran menutupnya, dan tanda tangannya tercatat.
        $this->actingAsRole('finance-manager');
        $resp = $this->postJson("/api/v1/finance/expense-requests/{$id}/approve")->assertOk();

        $request = FinanceExpenseRequest::find($id);
        $this->assertSame(FinanceExpenseRequest::STATUS_APPROVED, $request->status);
        $this->assertNotNull($request->budget_override_by);
        $this->assertNotNull($request->budget_override_at);
        $this->assertFalse($this->budgets()->awaitsBudgetAuthority($request));
        $this->assertNull($resp->json('data.budget_override_by_name'));
    }

    public function test_pengajuan_di_bawah_anggaran_tidak_meminta_hak_anggaran(): void
    {
        $this->control(FinanceBudgetService::CONTROL_APPROVAL);
        $this->budget(10000000);

        $id = $this->submitRequest(2000000);

        $outsider = $this->actingAsRole('finance-officer');
        $outsider->givePermissionTo('finance.expense.approve');
        $this->postJson("/api/v1/finance/expense-requests/{$id}/approve")
            ->assertOk()->assertJsonPath('data.status', FinanceExpenseRequest::STATUS_APPROVED);

        $this->assertNull(FinanceExpenseRequest::find($id)->budget_override_by);
    }

    public function test_pengeluaran_tanpa_anggaran_tidak_pernah_diblokir(): void
    {
        $this->control(FinanceBudgetService::CONTROL_BLOCK);

        // Tidak ada anggaran untuk 5600/CC-UMUM: tidak ada batas yang dilanggar.
        $id = $this->submitRequest(50000000, '5600', 'CC-UMUM');

        $request = FinanceExpenseRequest::find($id);
        $this->assertSame(FinanceExpenseRequest::STATUS_SUBMITTED, $request->status);
        $this->assertEquals(0.0, (float) $request->budget_shortfall);
        $this->assertFalse($request->overranBudget());
    }

    public function test_hasil_pemeriksaan_dibekukan_saat_diajukan(): void
    {
        $this->control(FinanceBudgetService::CONTROL_WARNING);
        $budget = $this->budget(10000000);

        $id = $this->submitRequest(12000000);

        $request = FinanceExpenseRequest::find($id);
        $this->assertSame(FinanceBudgetService::CONTROL_WARNING, $request->budget_control);
        $this->assertEquals(2000000.0, (float) $request->budget_shortfall);
        $this->assertEquals(12000000.0, $this->row($budget->fresh())['committed']);

        // Kebijakan diperkerat menjadi `block` setelah pengajuan masuk: yang
        // sudah berjalan tidak diulang pemeriksaannya dan tidak tiba-tiba jadi
        // batal — hanya pengajuan berikutnya yang kena aturan baru.
        $this->control(FinanceBudgetService::CONTROL_BLOCK);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/expense-requests/{$id}/approve")
            ->assertOk()->assertJsonPath('data.status', FinanceExpenseRequest::STATUS_APPROVED);

        $this->assertSame(FinanceBudgetService::CONTROL_WARNING, $request->fresh()->budget_control);
    }

    // =================================================================
    // §8 — status penutup anggaran
    // =================================================================

    public function test_anggaran_ditutup_tidak_menarik_komitmen_baru(): void
    {
        $this->control(FinanceBudgetService::CONTROL_BLOCK);
        $budget = $this->budget(10000000);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/budgets/{$budget->id}/close")
            ->assertOk()->assertJsonPath('data.status', FinanceBudget::STATUS_CLOSED);

        // Realisasinya tetap ada di laporan (filter status eksplisit), tapi
        // pengajuan baru tidak lagi menempel padanya.
        $closed = $this->budgets()->positions(['fiscal_year' => 2026, 'status' => FinanceBudget::STATUS_CLOSED]);
        $this->assertCount(1, $closed['rows']);
        $this->assertCount(0, $this->budgets()->positions(['fiscal_year' => 2026])['rows']);

        $verdict = $this->budgets()->check($this->account('5200'), $this->costCenter()->id, 5000000, '2026-09-10');
        $this->assertSame(FinanceBudgetService::CHECK_NO_BUDGET, $verdict['status']);
    }

    public function test_anggaran_yang_sudah_berlaku_tidak_bisa_dibatalkan(): void
    {
        $budget = $this->budget(10000000);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/budgets/{$budget->id}/cancel", [])
            ->assertStatus(422)->assertJsonValidationErrors('cancel_reason');
        $this->postJson("/api/v1/finance/budgets/{$budget->id}/cancel", ['cancel_reason' => 'Salah tahun'])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame(FinanceBudget::STATUS_ACTIVE, $budget->fresh()->status);
    }

    public function test_anggaran_draft_bisa_dibatalkan_dengan_alasan(): void
    {
        $this->actingAsRole('finance-admin');
        $id = (int) $this->postJson('/api/v1/finance/budgets', [
            'fiscal_year' => 2026,
            'account_id' => $this->account('5500'),
            'cost_center_id' => $this->costCenter()->id,
            'budget_amount' => 1000000,
        ])->assertCreated()->json('data.id');

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/budgets/{$id}/cancel", ['cancel_reason' => 'Unitnya tidak jadi berdiri'])
            ->assertOk()->assertJsonPath('data.status', FinanceBudget::STATUS_CANCELLED);

        $cancelled = FinanceBudget::find($id);
        $this->assertSame('Unitnya tidak jadi berdiri', $cancelled->cancel_reason);
        $this->assertNotNull($cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);
    }

    // =================================================================
    // API §9/§10
    // =================================================================

    public function test_api_budget_vs_actual_dan_availability(): void
    {
        $this->fundCash(20000000);
        $budget = $this->budget(10000000);
        $this->payRequest($this->submitRequest(3000000));

        $this->actingAsRole('finance-manager');
        $resp = $this->getJson('/api/v1/finance/budgets/positions?fiscal_year=2026')
            ->assertOk()
            ->assertJsonPath('data.rows.0.id', $budget->id)
            ->assertJsonPath('data.totals.actual', 3000000)
            ->assertJsonPath('data.totals.utilization', 30);

        // Nama kolom laporan = nama di §9, supaya ekspor CSV tidak berbeda makna.
        $resp->assertJsonStructure(['data' => ['rows' => [['budget_amount', 'actual', 'variance', 'committed', 'available', 'utilization']]]]);

        $this->getJson('/api/v1/finance/budgets/availability?account_id='.$this->account('5200')
            .'&cost_center_id='.$this->costCenter()->id.'&amount=2000000&date=2026-09-10')
            ->assertOk()
            ->assertJsonPath('data.status', FinanceBudgetService::CHECK_OK)
            ->assertJsonPath('data.available', 7000000);

        $this->getJson('/api/v1/finance/budgets/availability?account_id='.$this->account('5200')
            .'&cost_center_id='.$this->costCenter()->id.'&amount=8000000&date=2026-09-10')
            ->assertOk()
            ->assertJsonPath('data.status', FinanceBudgetService::CHECK_EXCEEDED)
            ->assertJsonPath('data.shortfall', 1000000);

        // Budget vs Actual hanya untuk pemegang hak anggaran.
        $this->actingAsRole('kasir');
        $this->getJson('/api/v1/finance/budgets/positions')->assertStatus(403);
    }

    public function test_detail_anggaran_memuat_posisi_dari_buku_besar(): void
    {
        $this->fundCash(20000000);
        $budget = $this->budget(10000000);
        $this->submitRequest(4000000);

        $this->actingAsRole('finance-manager');
        $this->getJson("/api/v1/finance/budgets/{$budget->id}")
            ->assertOk()
            ->assertJsonPath('data.position.actual', 0)
            ->assertJsonPath('data.position.committed', 4000000)
            ->assertJsonPath('data.position.available', 6000000);
    }

    public function test_laporan_budget_memakai_angka_buku_besar_yang_sama(): void
    {
        $this->fundCash(20000000);
        $budget = $this->budget(10000000);
        $this->payRequest($this->submitRequest(3000000));

        $this->actingAsRole('finance-manager');
        $row = $this->getJson('/api/v1/finance/reports/budget?fiscal_year=2026')
            ->assertOk()
            ->assertJsonPath('data.rows.0.actual', 3000000)
            ->assertJsonPath('data.rows.0.variance', 7000000)
            ->json('data.rows.0');

        // Layar, laporan dan ekspor membaca satu sumber yang sama (§9).
        $this->assertEquals($this->row($budget->fresh())['actual'], $row['actual']);
    }
}
