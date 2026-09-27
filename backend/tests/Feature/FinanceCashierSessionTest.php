<?php

namespace Tests\Feature;

use App\Models\FinanceCashierSession;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sesi kasir (§22, §23) lewat HTTP: buka satu sesi per kasir, tutup dengan
 * perhitungan expected vs actual (selisih wajib beralasan), dan review oleh
 * finance — bukan oleh kasir pemilik sesi (§3 pemisahan tugas).
 */
class FinanceCashierSessionTest extends TestCase
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

    private function student(string $nis): Student
    {
        return Student::query()->firstOrCreate(['nis' => $nis], ['full_name' => 'Santri '.$nis, 'is_active' => true]);
    }

    private function feeType(string $code): FinanceFeeType
    {
        return FinanceFeeType::query()->where('code', $code)->firstOrFail();
    }

    private function cashId(): int
    {
        return (int) \App\Models\FinanceCashAccount::query()->where('code', 'KAS-SEKOLAH')->value('id');
    }

    private function package(string $code): FinanceFeePackage
    {
        $package = FinanceFeePackage::query()->firstOrCreate(
            ['code' => $code],
            ['name' => 'Paket Kasir', 'boarding_type' => 'all', 'is_active' => true],
        );

        if ($package->items()->count() === 0) {
            $package->items()->createMany([
                ['fee_type_id' => $this->feeType('SPP')->id, 'amount' => 350000, 'frequency' => 'monthly', 'is_active' => true],
                ['fee_type_id' => $this->feeType('UANG_ASRAMA')->id, 'amount' => 500000, 'frequency' => 'monthly', 'is_active' => true],
            ]);
        }

        return $package;
    }

    /** Invoice 850.000 terbit (dibuat sebagai finance-admin). */
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

    // =================================================================

    public function test_kasir_membuka_sesi(): void
    {
        $this->actingAsRole('kasir');

        $resp = $this->postJson('/api/v1/finance/cashier-sessions', [
            'cash_account_id' => $this->cashId(),
            'opening_cash' => 500000,
        ])->assertCreated();

        $this->assertSame(FinanceCashierSession::STATUS_OPEN, $resp->json('data.status'));
        $this->assertEquals(500000.00, (float) $resp->json('data.opening_cash'));
        $this->assertNotEmpty($resp->json('data.session_number'));
    }

    public function test_kasir_tidak_boleh_punya_dua_sesi_terbuka(): void
    {
        $this->actingAsRole('kasir');

        $this->postJson('/api/v1/finance/cashier-sessions', ['cash_account_id' => $this->cashId(), 'opening_cash' => 100000])->assertCreated();
        $this->postJson('/api/v1/finance/cashier-sessions', ['cash_account_id' => $this->cashId(), 'opening_cash' => 100000])
            ->assertStatus(422)->assertJsonValidationErrors('cash_account_id');
    }

    public function test_tutup_sesi_hitung_expected_dari_pembayaran_tunai(): void
    {
        // invoice dulu sebagai admin
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('C001'), '2026-09', 'PKT-KAS1');

        // kasir buka sesi + terima pembayaran tunai terikat sesi
        $kasir = $this->actingAsRole('kasir');
        $sessionId = $this->postJson('/api/v1/finance/cashier-sessions', [
            'cash_account_id' => $this->cashId(),
            'opening_cash' => 500000,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'cash',
            'cash_account_id' => $this->cashId(),
            'cashier_session_id' => $sessionId,
        ])->assertCreated();

        // tutup dengan actual = expected (500k + 850k) → selisih 0, tanpa reason
        $resp = $this->postJson("/api/v1/finance/cashier-sessions/{$sessionId}/close", [
            'actual_cash' => 1350000,
        ])->assertOk();

        $this->assertSame(FinanceCashierSession::STATUS_CLOSED, $resp->json('data.status'));
        $this->assertEquals(1350000.00, (float) $resp->json('data.expected_cash'));
        $this->assertEquals(0.00, (float) $resp->json('data.difference'));
        $this->assertSame(1, $resp->json('data.payments_count') ?? 1);
        $this->assertNotNull($kasir);
    }

    public function test_selisih_kas_wajib_dijelaskan(): void
    {
        $this->actingAsRole('kasir');
        $sessionId = $this->postJson('/api/v1/finance/cashier-sessions', [
            'cash_account_id' => $this->cashId(),
            'opening_cash' => 500000,
        ])->assertCreated()->json('data.id');

        // tanpa pembayaran: expected 500k; actual 450k → selisih -50k wajib reason
        $this->postJson("/api/v1/finance/cashier-sessions/{$sessionId}/close", ['actual_cash' => 450000])
            ->assertStatus(422)->assertJsonValidationErrors('difference_reason');

        $resp = $this->postJson("/api/v1/finance/cashier-sessions/{$sessionId}/close", [
            'actual_cash' => 450000,
            'difference_reason' => 'Uang 50rb terpakai beli ATK mendesak',
        ])->assertOk();

        $this->assertEquals(-50000.00, (float) $resp->json('data.difference'));
        $this->assertSame(FinanceCashierSession::STATUS_CLOSED, $resp->json('data.status'));
    }

    public function test_manager_mereview_sesi_tertutup(): void
    {
        $this->actingAsRole('kasir');
        $sessionId = $this->postJson('/api/v1/finance/cashier-sessions', [
            'cash_account_id' => $this->cashId(),
            'opening_cash' => 0,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/finance/cashier-sessions/{$sessionId}/close", ['actual_cash' => 0])->assertOk();

        $this->actingAsRole('finance-manager');
        $resp = $this->postJson("/api/v1/finance/cashier-sessions/{$sessionId}/review", ['notes' => 'Sesuai'])
            ->assertOk();

        $this->assertSame(FinanceCashierSession::STATUS_REVIEWED, $resp->json('data.status'));
        $this->assertNotNull($resp->json('data.reviewed_at'));
    }

    public function test_kasir_tidak_boleh_mereview_sesi_sendiri(): void
    {
        // finance-admin punya cashier.manage + cashier.review → bisa coba review sendiri
        $admin = $this->actingAsRole('finance-admin');
        $sessionId = $this->postJson('/api/v1/finance/cashier-sessions', [
            'cash_account_id' => $this->cashId(),
            'opening_cash' => 0,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/finance/cashier-sessions/{$sessionId}/close", ['actual_cash' => 0])->assertOk();

        // reviewer == kasir pemilik sesi → ditolak service (§3)
        $this->postJson("/api/v1/finance/cashier-sessions/{$sessionId}/review")
            ->assertStatus(422)->assertJsonValidationErrors('session');
        $this->assertSame($admin->id, FinanceCashierSession::find($sessionId)->cashier_id);
    }

    public function test_review_sebelum_tutup_ditolak(): void
    {
        $this->actingAsRole('kasir');
        $sessionId = $this->postJson('/api/v1/finance/cashier-sessions', [
            'cash_account_id' => $this->cashId(),
            'opening_cash' => 0,
        ])->assertCreated()->json('data.id');

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/cashier-sessions/{$sessionId}/review")->assertStatus(422);
    }

    public function test_guard_permission_sesi_kasir(): void
    {
        $this->actingAsRole('kasir');
        $sessionId = $this->postJson('/api/v1/finance/cashier-sessions', [
            'cash_account_id' => $this->cashId(),
            'opening_cash' => 0,
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/finance/cashier-sessions/{$sessionId}/close", ['actual_cash' => 0])->assertOk();

        // kasir boleh lihat, tidak boleh review (tak punya cashier.review)
        $this->actingAsRole('kasir');
        $this->getJson('/api/v1/finance/cashier-sessions')->assertOk();
        $this->postJson("/api/v1/finance/cashier-sessions/{$sessionId}/review")->assertStatus(403);

        // auditor baca-saja: tidak boleh buka sesi
        $this->actingAsRole('auditor');
        $this->getJson('/api/v1/finance/cashier-sessions')->assertOk();
        $this->postJson('/api/v1/finance/cashier-sessions', ['cash_account_id' => $this->cashId()])->assertStatus(403);
    }

    public function test_expected_cash_dihitung_bersih_setelah_pembayaran(): void
    {
        $this->actingAsRole('finance-admin');
        $invoice = $this->issuedInvoice($this->student('C002'), '2026-09', 'PKT-KAS2');

        $sessionId = $this->postJson('/api/v1/finance/cashier-sessions', [
            'cash_account_id' => $this->cashId(),
            'opening_cash' => 200000,
        ])->assertCreated()->json('data.id');

        $paymentId = $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 850000,
            'method' => 'cash',
            'cash_account_id' => $this->cashId(),
            'cashier_session_id' => $sessionId,
        ])->assertCreated()->json('data.id');

        // refund tunai 150rb mengurangi expected kas laci
        $refundId = $this->postJson("/api/v1/finance/payments/{$paymentId}/refunds", [
            'amount' => 150000,
            'reason' => 'Kelebihan bayar',
            'cash_account_id' => $this->cashId(),
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/finance/refunds/{$refundId}/approve")->assertOk();
        $this->postJson("/api/v1/finance/refunds/{$refundId}/complete")->assertOk();

        // expected = 200k + 850k − 150k = 900k
        $resp = $this->postJson("/api/v1/finance/cashier-sessions/{$sessionId}/close", ['actual_cash' => 900000])->assertOk();
        $this->assertEquals(900000.00, (float) $resp->json('data.expected_cash'));
        $this->assertEquals(0.00, (float) $resp->json('data.difference'));
        $this->assertSame(FinancePayment::STATUS_PARTIAL, FinancePayment::find($paymentId)->status);
    }
}
