<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceBudget;
use App\Models\FinanceCashAccount;
use App\Models\FinanceCostCenter;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinancePeriod;
use App\Models\FinancialTransaction;
use App\Models\User;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Master-data Finance lewat HTTP (§5, §6, §8, §9, §11, §12, §39, §48, §49, §50,
 * §56). Menguji CRUD COA + tree + proteksi akun sistem, cost center, alur approval
 * anggaran (submit/approve/reject + tolak self-approval + scope unik), fee type &
 * paket dengan sinkronisasi item, kas/bank + posting saldo awal lewat ledger, dan
 * penutupan/pembukaan periode. Guard permission diuji lintas peran.
 */
class FinanceMasterTest extends TestCase
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

    private function account(string $code): FinanceAccount
    {
        return FinanceAccount::query()->where('code', $code)->firstOrFail();
    }

    private function costCenter(string $code): FinanceCostCenter
    {
        return FinanceCostCenter::query()->where('code', $code)->firstOrFail();
    }

    private function feeType(string $code): FinanceFeeType
    {
        return FinanceFeeType::query()->where('code', $code)->firstOrFail();
    }

    private function cash(string $code = 'KAS-SEKOLAH'): FinanceCashAccount
    {
        return FinanceCashAccount::query()->where('code', $code)->firstOrFail();
    }

    // =================================================================
    // Chart of Accounts (§5, §6)
    // =================================================================

    public function test_daftar_dan_tree_coa_dapat_diambil(): void
    {
        $this->actingAsRole('finance-admin');

        $this->getJson('/api/v1/finance/accounts?all=1')->assertOk()->assertJsonPath('success', true);

        $tree = $this->getJson('/api/v1/finance/accounts/tree')->assertOk()->json('data');
        $codes = array_column($tree, 'code');
        $this->assertContains('1000', $codes); // root Aset
        $this->assertContains('4000', $codes); // root Pendapatan
    }

    public function test_buat_akun_baru_dan_tolak_induk_beda_tipe(): void
    {
        $this->actingAsRole('finance-admin');

        $this->postJson('/api/v1/finance/accounts', [
            'code' => '4199',
            'name' => 'Pendapatan Ekstra',
            'type' => 'revenue',
            'parent_id' => $this->account('4000')->id,
        ])->assertCreated()->assertJsonPath('data.code', '4199')
            ->assertJsonPath('data.normal_balance', 'credit');

        // Induk asset untuk akun revenue harus ditolak.
        $this->postJson('/api/v1/finance/accounts', [
            'code' => '4198',
            'name' => 'Salah Induk',
            'type' => 'revenue',
            'parent_id' => $this->account('1000')->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['parent_id']);
    }

    public function test_akun_sistem_tidak_boleh_dihapus(): void
    {
        $this->actingAsRole('finance-admin');

        $this->deleteJson('/api/v1/finance/accounts/'.$this->account('4100')->id)
            ->assertStatus(422);

        $this->assertNotNull($this->account('4100'));
    }

    public function test_akun_dipakai_jurnal_tidak_boleh_dihapus(): void
    {
        $this->actingAsRole('finance-admin');

        $resp = $this->postJson('/api/v1/finance/accounts', [
            'code' => '5999',
            'name' => 'Beban Uji',
            'type' => 'expense',
            'parent_id' => $this->account('5000')->id,
        ])->assertCreated();
        $id = $resp->json('data.id');

        $engine = app(\App\Services\Finance\FinancialTransactionEngine::class);
        $kas = $this->cash();
        $engine->cashReceipt($kas, $this->account('4100')->id, 100000, 'Setor');
        $engine->cashDisbursement($kas, $id, 1000, 'Pakai akun beban uji');

        $this->assertTrue(
            FinancialTransaction::query()->whereHas('lines', fn ($q) => $q->where('account_id', $id))->exists()
        );

        // Akun sudah dipakai jurnal → hapus ditolak.
        $this->deleteJson("/api/v1/finance/accounts/{$id}")->assertStatus(422);
    }

    // =================================================================
    // Cost center (§7)
    // =================================================================

    public function test_cost_center_crud_dan_proteksi_hapus(): void
    {
        $this->actingAsRole('finance-admin');

        $resp = $this->postJson('/api/v1/finance/cost-centers', [
            'code' => 'cc-uji',
            'name' => 'Unit Uji',
        ])->assertCreated();
        $id = $resp->json('data.id');

        $this->putJson("/api/v1/finance/cost-centers/{$id}", ['name' => 'Unit Uji 2'])
            ->assertOk()->assertJsonPath('data.name', 'Unit Uji 2');

        // Cost center hasil seed punya akun kas → tidak boleh dihapus.
        $this->deleteJson('/api/v1/finance/cost-centers/'.$this->costCenter('CC-SEKOLAH')->id)
            ->assertStatus(422);

        $this->deleteJson("/api/v1/finance/cost-centers/{$id}")->assertOk();
    }

    // =================================================================
    // Anggaran & approval (§8, §9)
    // =================================================================

    public function test_alur_approval_anggaran_submit_approve(): void
    {
        // finance-admin punya scope penuh: membuat + mengajukan.
        $admin = $this->actingAsRole('finance-admin');

        $resp = $this->postJson('/api/v1/finance/budgets', [
            'fiscal_year' => 2026,
            'account_id' => $this->account('5500')->id,
            'cost_center_id' => $this->costCenter('CC-SEKOLAH')->id,
            'period' => 'TA-2026',
            'budget_amount' => 12000000,
        ])->assertCreated()->assertJsonPath('data.status', 'draft');
        $budgetId = $resp->json('data.id');

        $this->postJson("/api/v1/finance/budgets/{$budgetId}/submit")
            ->assertOk()->assertJsonPath('data.status', 'submitted');

        // Self-approval ditolak: admin yang mengajukan tidak boleh menyetujui sendiri.
        $this->postJson("/api/v1/finance/budgets/{$budgetId}/approve")->assertStatus(422);

        // Manager (penyetuju berbeda) menyetujui.
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/budgets/{$budgetId}/approve")
            ->assertOk()->assertJsonPath('data.status', 'active');

        // Setelah active tidak boleh diedit.
        $this->actingAsRole('finance-admin');
        $this->putJson("/api/v1/finance/budgets/{$budgetId}", ['budget_amount' => 1])->assertStatus(422);
    }

    public function test_anggaran_dapat_ditolak_dengan_alasan(): void
    {
        $this->actingAsRole('finance-admin');

        $resp = $this->postJson('/api/v1/finance/budgets', [
            'fiscal_year' => 2026,
            'account_id' => $this->account('5400')->id,
            'cost_center_id' => $this->costCenter('CC-UMUM')->id,
            'budget_amount' => 5000000,
        ])->assertCreated();
        $budgetId = $resp->json('data.id');

        $this->postJson("/api/v1/finance/budgets/{$budgetId}/submit")->assertOk();

        $this->actingAsRole('finance-manager');
        // Tanpa alasan → ditolak validasi.
        $this->postJson("/api/v1/finance/budgets/{$budgetId}/reject")->assertStatus(422);
        $this->postJson("/api/v1/finance/budgets/{$budgetId}/reject", ['reject_reason' => 'Terlalu besar'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        // Anggaran rejected boleh diedit lagi.
        $this->actingAsRole('finance-admin');
        $editResp = $this->putJson("/api/v1/finance/budgets/{$budgetId}", ['budget_amount' => 3000000])
            ->assertOk();
        $this->assertEquals(3000000.00, (float) $editResp->json('data.budget_amount'));
    }

    public function test_scope_anggaran_unik(): void
    {
        $this->actingAsRole('finance-admin');

        $payload = [
            'fiscal_year' => 2027,
            'account_id' => $this->account('5200')->id,
            'cost_center_id' => $this->costCenter('CC-PESANTREN')->id,
            'budget_amount' => 1000000,
        ];

        $this->postJson('/api/v1/finance/budgets', $payload)->assertCreated();
        $this->postJson('/api/v1/finance/budgets', $payload)
            ->assertStatus(422)->assertJsonValidationErrors(['account_id']);
    }

    // =================================================================
    // Fee type & paket (§11, §12)
    // =================================================================

    public function test_paket_tagihan_dengan_item_dibuat_atomik_dan_total_terhitung(): void
    {
        $this->actingAsRole('finance-admin');

        $resp = $this->postJson('/api/v1/finance/fee-packages', [
            'code' => 'pkt-smp-board',
            'name' => 'Paket SMP Boarding',
            'boarding_type' => 'boarding',
            'academic_year' => 2026,
            'items' => [
                ['fee_type_id' => $this->feeType('SPP')->id, 'amount' => 350000, 'frequency' => 'monthly'],
                ['fee_type_id' => $this->feeType('UANG_ASRAMA')->id, 'amount' => 500000, 'frequency' => 'monthly'],
                ['fee_type_id' => $this->feeType('SERAGAM')->id, 'amount' => 750000, 'frequency' => 'once'],
            ],
        ])->assertCreated();

        $packageId = $resp->json('data.id');
        $this->assertEquals(3, $resp->json('data.items_count'));

        $show = $this->getJson("/api/v1/finance/fee-packages/{$packageId}")->assertOk();
        $this->assertEquals(1600000.00, (float) $show->json('data.total'));

        // Update: ganti item → sinkronisasi hapus yang tidak dikirim.
        $this->putJson("/api/v1/finance/fee-packages/{$packageId}", [
            'name' => 'Paket SMP Boarding Revisi',
            'items' => [
                ['fee_type_id' => $this->feeType('SPP')->id, 'amount' => 400000, 'frequency' => 'monthly'],
            ],
        ])->assertOk();

        $after = $this->getJson("/api/v1/finance/fee-packages/{$packageId}")->assertOk();
        $this->assertEquals(1, $after->json('data.items_count'));
        $this->assertEquals(400000.00, (float) $after->json('data.total'));
    }

    public function test_fee_type_dipakai_item_tidak_boleh_dihapus(): void
    {
        $this->actingAsRole('finance-admin');

        $this->postJson('/api/v1/finance/fee-packages', [
            'code' => 'pkt-hapus',
            'name' => 'Paket Hapus',
            'items' => [['fee_type_id' => $this->feeType('KEGIATAN')->id, 'amount' => 100000]],
        ])->assertCreated();

        $this->deleteJson('/api/v1/finance/fee-types/'.$this->feeType('KEGIATAN')->id)
            ->assertStatus(422);
    }

    // =================================================================
    // Kas / bank + saldo awal (§39, §50)
    // =================================================================

    public function test_buat_kas_dan_posting_saldo_awal_lewat_ledger(): void
    {
        $this->actingAsRole('finance-admin');

        $resp = $this->postJson('/api/v1/finance/cash-accounts', [
            'code' => 'kas-uji',
            'name' => 'Kas Uji',
            'account_id' => $this->account('1100')->id,
            'cost_center_id' => $this->costCenter('CC-UMUM')->id,
        ])->assertCreated();
        $cashId = $resp->json('data.id');
        $this->assertEquals(0.0, (float) $resp->json('data.current_balance'));

        // Saldo awal tidak boleh diset lewat update.
        $this->putJson("/api/v1/finance/cash-accounts/{$cashId}", ['current_balance' => 999999])
            ->assertOk();
        $this->assertEquals(0.0, (float) FinanceCashAccount::find($cashId)->current_balance);

        // Posting saldo awal → jurnal opening + saldo kas bergerak.
        $this->postJson("/api/v1/finance/cash-accounts/{$cashId}/opening-balance", [
            'amount' => 250000,
            'note' => 'Setoran awal',
        ])->assertOk();

        $this->assertEquals(250000.00, (float) FinanceCashAccount::find($cashId)->current_balance);
        $this->assertTrue(FinancialTransaction::query()->where('type', FinancialTransaction::TYPE_OPENING)->exists());

        // Kas bersaldo tidak boleh dihapus.
        $this->deleteJson("/api/v1/finance/cash-accounts/{$cashId}")->assertStatus(422);
    }

    public function test_saldo_awal_ganda_ditolak(): void
    {
        $this->actingAsRole('finance-admin');

        $cashId = $this->cash()->id;
        $this->postJson("/api/v1/finance/cash-accounts/{$cashId}/opening-balance", ['amount' => 100000])
            ->assertOk();
        // Kedua kali → sudah punya saldo, harus lewat adjustment.
        $this->postJson("/api/v1/finance/cash-accounts/{$cashId}/opening-balance", ['amount' => 50000])
            ->assertStatus(422);
    }

    // =================================================================
    // Periode & closing (§48, §49)
    // =================================================================

    public function test_ringkasan_dan_generate_periode(): void
    {
        $this->actingAsRole('finance-manager');

        $this->getJson('/api/v1/finance/periods/summary')->assertOk()->assertJsonStructure([
            'data' => ['fiscal_year', 'total', 'open', 'closed'],
        ]);

        // Tahun baru, idempoten.
        $first = $this->postJson('/api/v1/finance/periods/generate', ['fiscal_year' => 2027])->assertOk()->json('data');
        $this->assertCount(12, $first);
        $this->postJson('/api/v1/finance/periods/generate', ['fiscal_year' => 2027])->assertOk();
        $this->assertSame(12, FinancePeriod::query()->where('fiscal_year', 2027)->count());
    }

    public function test_close_dan_reopen_periode(): void
    {
        $this->actingAsRole('finance-manager');

        $period = FinancePeriod::query()->orderBy('start_date')->first();

        $this->postJson("/api/v1/finance/periods/{$period->id}/close")
            ->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.is_open', false);

        // Tutup dua kali ditolak.
        $this->postJson("/api/v1/finance/periods/{$period->id}/close")->assertStatus(422);

        $this->postJson("/api/v1/finance/periods/{$period->id}/reopen")
            ->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.is_open', true);
    }

    // =================================================================
    // Settings (§73)
    // =================================================================

    public function test_settings_dibaca_dan_diperbarui(): void
    {
        $this->actingAsRole('finance-admin');

        $this->getJson('/api/v1/finance/settings')->assertOk()->assertJsonPath('data.currency', 'IDR');

        $this->putJson('/api/v1/finance/settings', [
            'settings' => ['late_fee_enabled' => true, 'late_fee_grace_days' => 3],
        ])->assertOk();

        $this->getJson('/api/v1/finance/settings')
            ->assertOk()
            ->assertJsonPath('data.late_fee_enabled', true)
            ->assertJsonPath('data.late_fee_grace_days', 3);
    }

    // =================================================================
    // Guard permission (§3, §56)
    // =================================================================

    public function test_auditor_baca_saja_boleh_lihat_tapi_tidak_boleh_tulis(): void
    {
        // Auditor = read-only seluruh finance (§56): boleh baca, dilarang menulis.
        $this->actingAsRole('auditor');
        $this->getJson('/api/v1/finance/accounts?all=1')->assertOk();
        $this->postJson('/api/v1/finance/accounts', [
            'code' => '9999', 'name' => 'X', 'type' => 'asset',
        ])->assertStatus(403);
        $this->deleteJson('/api/v1/finance/accounts/'.$this->account('4100')->id)->assertStatus(403);
    }

    public function test_wali_murid_tidak_punya_akses_keuangan(): void
    {
        $this->actingAsRole('wali-murid');
        $this->getJson('/api/v1/finance/accounts?all=1')->assertStatus(403);
    }

    public function test_officer_tidak_boleh_menutup_periode(): void
    {
        $this->actingAsRole('finance-officer');
        $period = FinancePeriod::query()->orderBy('start_date')->first();
        $this->postJson("/api/v1/finance/periods/{$period->id}/close")->assertStatus(403);
    }

    public function test_officer_tidak_boleh_menyetujui_anggaran(): void
    {
        // Admin menyiapkan anggaran yang sudah diajukan.
        $this->actingAsRole('finance-admin');
        $resp = $this->postJson('/api/v1/finance/budgets', [
            'fiscal_year' => 2028,
            'account_id' => $this->account('5300')->id,
            'cost_center_id' => $this->costCenter('CC-PESANTREN')->id,
            'budget_amount' => 900000,
        ])->assertCreated();
        $budgetId = $resp->json('data.id');
        $this->postJson("/api/v1/finance/budgets/{$budgetId}/submit")->assertOk();

        // Officer tidak punya finance.budget.approve → 403 di middleware.
        $this->actingAsRole('finance-officer');
        $this->postJson("/api/v1/finance/budgets/{$budgetId}/approve")->assertStatus(403);
    }
}
