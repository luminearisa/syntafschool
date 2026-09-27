<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceCostCenter;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dashboard keuangan (§67) lewat HTTP.
 *
 * Yang diverifikasi: bentuk respons (kas, piutang, performa periode, anggaran,
 * antrean persetujuan, jurnal terbaru); posisi kas/bank mencerminkan saldo akun
 * aktif; piutang mencerminkan invoice outstanding; pendapatan bulan berjalan
 * dibaca dari jurnal posted; antrean persetujuan menghitung dokumen diajukan;
 * guard permission (butuh finance.view).
 */
class FinanceDashboardTest extends TestCase
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

    private function account(string $code): int
    {
        return (int) FinanceAccount::query()->where('code', $code)->value('id');
    }

    private function cash(string $code = 'KAS-SEKOLAH'): FinanceCashAccount
    {
        return FinanceCashAccount::query()->where('code', $code)->firstOrFail();
    }

    private function bank(string $code = 'BANK-OPS'): FinanceBankAccount
    {
        return FinanceBankAccount::query()->where('code', $code)->firstOrFail();
    }

    private function costCenter(string $code = 'CC-SEKOLAH'): FinanceCostCenter
    {
        return FinanceCostCenter::query()->where('code', $code)->firstOrFail();
    }

    private function student(string $nis): Student
    {
        return Student::query()->firstOrCreate(['nis' => $nis], ['full_name' => 'Santri '.$nis, 'is_active' => true]);
    }

    private function feeType(string $code): FinanceFeeType
    {
        return FinanceFeeType::query()->where('code', $code)->firstOrFail();
    }

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

    private function issue(Student $student, string $pkg): FinanceInvoice
    {
        $package = $this->package($pkg);

        $billingId = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id,
            'period_key' => '2026-09',
            'student_ids' => [$student->id],
            'due_date' => '2026-09-30',
        ])->assertOk()->json('data.billings.0');

        return FinanceInvoice::find(
            $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated()->json('data.id')
        );
    }

    // =================================================================

    public function test_overview_mengembalikan_seluruh_bagian_kpi(): void
    {
        $this->actingAsRole('finance-admin');

        $data = $this->getJson('/api/v1/finance/dashboard')->assertOk()->json('data');

        foreach (['as_of', 'cash', 'receivable', 'period', 'budget', 'pending', 'recent_transactions'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }

        $this->assertArrayHasKey('combined_total', $data['cash']);
        $this->assertArrayHasKey('total_receivable', $data['receivable']);
        $this->assertArrayHasKey('net', $data['period']);
        $this->assertIsArray($data['recent_transactions']);
    }

    public function test_posisi_kas_mencerminkan_saldo_akun_aktif(): void
    {
        $this->actingAsRole('finance-admin');
        $this->cash('KAS-SEKOLAH')->update(['current_balance' => 500000]);
        $this->bank('BANK-OPS')->update(['current_balance' => 1000000]);

        $cash = $this->getJson('/api/v1/finance/dashboard')->assertOk()->json('data.cash');

        $this->assertGreaterThanOrEqual(500000.00, (float) $cash['cash_total']);
        $this->assertGreaterThanOrEqual(1000000.00, (float) $cash['bank_total']);
        $this->assertEquals(
            round((float) $cash['cash_total'] + (float) $cash['bank_total'], 2),
            (float) $cash['combined_total'],
        );
    }

    public function test_piutang_dan_pendapatan_periode_dari_invoice_dan_jurnal(): void
    {
        $this->actingAsRole('finance-admin');
        $this->issue($this->student('DSH001'), 'PKT-DSH');

        $data = $this->getJson('/api/v1/finance/dashboard')->assertOk()->json('data');

        $this->assertEquals(850000.00, (float) $data['receivable']['total_receivable']);
        // Akrual pendapatan diposting bulan berjalan.
        $this->assertEquals(850000.00, (float) $data['period']['revenue']);
    }

    public function test_antrean_persetujuan_menghitung_dokumen_diajukan(): void
    {
        $this->actingAsRole('kepala-unit');
        $this->postJson('/api/v1/finance/expense-requests', [
            'cost_center_id' => $this->costCenter()->id,
            'account_id' => $this->account('5200'),
            'amount' => 1500000,
            'description' => 'Belanja unit',
            'expense_date' => '2026-09-10',
            'submit' => true,
        ])->assertCreated();

        $this->actingAsRole('finance-manager');
        $pending = $this->getJson('/api/v1/finance/dashboard')->assertOk()->json('data.pending');

        $this->assertSame(1, (int) $pending['expense_requests']);
    }

    public function test_butuh_permission_untuk_melihat_dashboard(): void
    {
        $this->actingAsRole('siswa');
        $this->getJson('/api/v1/finance/dashboard')->assertStatus(403);
    }
}
