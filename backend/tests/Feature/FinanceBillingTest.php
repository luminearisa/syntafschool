<?php

namespace Tests\Feature;

use App\Models\FinanceAccount;
use App\Models\FinanceBilling;
use App\Models\FinanceDiscount;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\FinanceScholarship;
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
 * Mesin penagihan (§13–§18) + invoice & jurnal akrual (§16, §46) lewat HTTP.
 *
 * Yang diverifikasi: generasi billing idempoten per (santri, paket, periode) §14,
 * diskon persentase per baris vs nominal global sekali potong §17, beasiswa aktif
 * mengurangi tagihan §18, penerbitan invoice memposting debit piutang/kredit
 * pendapatan §46, invoice idempoten per billing, pembatalan membalik jurnal §51,
 * billing manual, dan guard permission.
 */
class FinanceBillingTest extends TestCase
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

    private function student(string $nis = 'S001'): Student
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

    private function account(string $code): FinanceAccount
    {
        return FinanceAccount::query()->where('code', $code)->firstOrFail();
    }

    /** Paket berisi SPP 350.000 + Uang Asrama 500.000 (total 850.000). */
    private function package(string $code = 'PKT-UJI'): FinanceFeePackage
    {
        $package = FinanceFeePackage::query()->firstOrCreate(
            ['code' => $code],
            ['name' => 'Paket Uji', 'boarding_type' => 'all', 'is_active' => true],
        );

        if ($package->items()->count() === 0) {
            $package->items()->createMany([
                ['fee_type_id' => $this->feeType('SPP')->id, 'amount' => 350000, 'frequency' => 'monthly', 'is_active' => true],
                ['fee_type_id' => $this->feeType('UANG_ASRAMA')->id, 'amount' => 500000, 'frequency' => 'monthly', 'is_active' => true],
            ]);
        }

        return $package;
    }

    // =================================================================
    // Generasi billing (§13, §14)
    // =================================================================

    public function test_daftar_billing_memuat_nomor_invoice_yang_sudah_diterbitkan(): void
    {
        $this->actingAsRole('finance-admin');
        $package = $this->package('PKT-LIST-INVOICE');
        $student = $this->student('S-LIST-INVOICE');

        $generated = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id,
            'period_key' => '2026-11',
            'student_ids' => [$student->id],
        ])->assertOk();
        $billingId = (int) $generated->json('data.billings.0');
        $invoice = $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")
            ->assertCreated()
            ->json('data');

        $this->getJson('/api/v1/finance/billings')
            ->assertOk()
            ->assertJsonPath('data.0.invoice_number', $invoice['invoice_number']);
    }

    public function test_generate_billing_dari_paket_dan_idempoten(): void
    {
        $this->actingAsRole('finance-admin');
        $package = $this->package();
        $student = $this->student();

        $first = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id,
            'period_key' => '2026-09',
            'student_ids' => [$student->id],
            'due_date' => '2026-09-30',
        ])->assertOk()->assertJsonPath('data.generated', 1)->assertJsonPath('data.skipped', 0);

        $billingId = $first->json('data.billings.0');

        // Panggil lagi periode sama → tidak dobel, dilaporkan skipped.
        $second = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id,
            'period_key' => '2026-09',
            'student_ids' => [$student->id],
        ])->assertOk()->assertJsonPath('data.generated', 0)->assertJsonPath('data.skipped', 1);

        $this->assertSame($billingId, $second->json('data.billings.0'));
        $this->assertSame(1, FinanceBilling::query()->where('student_id', $student->id)->count());

        $billing = FinanceBilling::find($billingId);
        $this->assertEquals(850000.00, (float) $billing->total);
        $this->assertEquals(850000.00, (float) $billing->remaining_amount);
        $this->assertSame(FinanceBilling::STATUS_ISSUED, $billing->status);
        $this->assertCount(2, $billing->items);
    }

    public function test_billing_tanpa_potongan_menghasilkan_total_penuh(): void
    {
        $this->actingAsRole('finance-admin');
        $package = $this->package('PKT-BERSIH');
        $student = $this->student('S010');

        $resp = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id,
            'period_key' => '2026-10',
            'student_ids' => [$student->id],
        ])->assertOk();

        $billing = FinanceBilling::find($resp->json('data.billings.0'));
        $this->assertEquals(0.00, (float) $billing->discount_amount);
        $this->assertEquals(0.00, (float) $billing->scholarship_amount);
        $this->assertEquals(850000.00, (float) $billing->total);
    }

    // =================================================================
    // Diskon (§17)
    // =================================================================

    public function test_diskon_persentase_global_mengurangi_tiap_baris(): void
    {
        $this->actingAsRole('finance-admin');
        $package = $this->package('PKT-DISC-PCT');
        $student = $this->student('S020');

        // 10% untuk semua jenis tagihan.
        FinanceDiscount::create([
            'name' => 'Diskon 10%', 'type' => 'percentage', 'value' => 10,
            'is_active' => true,
        ]);

        $resp = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id,
            'period_key' => '2026-09',
            'student_ids' => [$student->id],
        ])->assertOk();

        $billing = FinanceBilling::find($resp->json('data.billings.0'));
        // 10% dari 850.000 = 85.000.
        $this->assertEquals(85000.00, (float) $billing->discount_amount);
        $this->assertEquals(765000.00, (float) $billing->total);
    }

    public function test_diskon_nominal_global_dipotong_sekali_bukan_per_baris(): void
    {
        $this->actingAsRole('finance-admin');
        $package = $this->package('PKT-DISC-FIX');
        $student = $this->student('S021');

        // Potongan tetap Rp100.000 untuk seluruh billing (bukan per baris).
        FinanceDiscount::create([
            'name' => 'Potongan 100rb', 'type' => 'fixed', 'value' => 100000,
            'is_active' => true,
        ]);

        $resp = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id,
            'period_key' => '2026-09',
            'student_ids' => [$student->id],
        ])->assertOk();

        $billing = FinanceBilling::find($resp->json('data.billings.0'));
        $this->assertEquals(100000.00, (float) $billing->discount_amount);
        $this->assertEquals(750000.00, (float) $billing->total);
    }

    public function test_diskon_terikat_jenis_tagihan_hanya_mengenai_baris_itu(): void
    {
        $this->actingAsRole('finance-admin');
        $package = $this->package('PKT-DISC-FEE');
        $student = $this->student('S022');

        // Diskon 50.000 hanya untuk SPP.
        FinanceDiscount::create([
            'name' => 'Diskon SPP', 'type' => 'fixed', 'value' => 50000,
            'fee_type_id' => $this->feeType('SPP')->id, 'is_active' => true,
        ]);

        $resp = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id,
            'period_key' => '2026-09',
            'student_ids' => [$student->id],
        ])->assertOk();

        $billing = FinanceBilling::find($resp->json('data.billings.0'));
        $this->assertEquals(50000.00, (float) $billing->discount_amount);
        $this->assertEquals(800000.00, (float) $billing->total);

        $sppLine = $billing->items()->where('fee_type_id', $this->feeType('SPP')->id)->first();
        $asramaLine = $billing->items()->where('fee_type_id', $this->feeType('UANG_ASRAMA')->id)->first();
        $this->assertEquals(50000.00, (float) $sppLine->discount_amount);
        $this->assertEquals(0.00, (float) $asramaLine->discount_amount);
    }

    // =================================================================
    // Beasiswa (§18)
    // =================================================================

    public function test_beasiswa_aktif_mengurangi_tagihan(): void
    {
        $this->actingAsRole('finance-admin');
        $package = $this->package('PKT-SCHOL');
        $student = $this->student('S030');

        // Beasiswa draft TIDAK boleh mengurangi.
        $draft = FinanceScholarship::create([
            'student_id' => $student->id, 'type' => 'fixed', 'amount' => 200000,
            'start_date' => '2026-01-01', 'status' => FinanceScholarship::STATUS_DRAFT,
        ]);

        $resp = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id, 'period_key' => '2026-09', 'student_ids' => [$student->id],
        ])->assertOk();
        $billing = FinanceBilling::find($resp->json('data.billings.0'));
        $this->assertEquals(0.00, (float) $billing->scholarship_amount);
        $this->assertEquals(850000.00, (float) $billing->total);

        // Setujui beasiswa → generate periode berikutnya ikut terpotong.
        $this->postJson("/api/v1/finance/scholarships/{$draft->id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'active');

        $resp2 = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id, 'period_key' => '2026-10', 'student_ids' => [$student->id],
        ])->assertOk();
        $billing2 = FinanceBilling::find($resp2->json('data.billings.0'));
        $this->assertEquals(200000.00, (float) $billing2->scholarship_amount);
        $this->assertEquals(650000.00, (float) $billing2->total);
    }

    public function test_beasiswa_persentase_terikat_jenis_tagihan(): void
    {
        $this->actingAsRole('finance-admin');
        $package = $this->package('PKT-SCHOL-PCT');
        $student = $this->student('S031');

        FinanceScholarship::create([
            'student_id' => $student->id, 'type' => 'percentage', 'percentage' => 50,
            'fee_type_id' => $this->feeType('SPP')->id,
            'start_date' => '2026-01-01', 'status' => FinanceScholarship::STATUS_ACTIVE,
        ]);

        $resp = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id, 'period_key' => '2026-09', 'student_ids' => [$student->id],
        ])->assertOk();

        $billing = FinanceBilling::find($resp->json('data.billings.0'));
        // 50% dari SPP 350.000 = 175.000; asrama tidak tersentuh.
        $this->assertEquals(175000.00, (float) $billing->scholarship_amount);
        $this->assertEquals(675000.00, (float) $billing->total);
    }

    // =================================================================
    // Invoice + jurnal akrual (§16, §46)
    // =================================================================

    public function test_terbitkan_invoice_memposting_jurnal_akrual_seimbang(): void
    {
        $this->actingAsRole('finance-admin');
        $package = $this->package('PKT-INV');
        $student = $this->student('S040');

        $resp = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id, 'period_key' => '2026-09', 'student_ids' => [$student->id],
        ])->assertOk();
        $billingId = $resp->json('data.billings.0');

        $inv = $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")
            ->assertCreated()->assertJsonPath('data.status', 'issued');
        $invoiceId = $inv->json('data.id');

        $invoice = FinanceInvoice::find($invoiceId);
        $this->assertEquals(850000.00, (float) $invoice->total);
        $this->assertEquals(850000.00, (float) $invoice->remaining_amount);
        $this->assertSame($billingId, $invoice->billing_id);

        // Jurnal akrual: debit piutang 1300, kredit pendapatan (4100 SPP + 4200 asrama).
        $journal = FinancialTransaction::query()
            ->where('reference_type', FinanceInvoice::class)
            ->where('reference_id', $invoiceId)
            ->firstOrFail();

        // Jurnal penerbitan invoice (§3): tipenya `invoice`, bukan `revenue` —
        // revenue adalah tipe untuk pengakuan pendapatan tanpa dokumen tagihan.
        $this->assertSame(FinancialTransaction::TYPE_INVOICE, $journal->type);
        $this->assertTrue($journal->isBalanced());
        $this->assertEquals(850000.00, (float) $journal->total_debit);
        $this->assertNotNull($journal->posted_at);
        $this->assertSame($journal->created_by, $journal->posted_by);
        $this->assertSame('accrual', $journal->metadata['basis'] ?? null);

        $debitAr = FinancialTransactionLine::query()
            ->where('finance_transaction_id', $journal->id)
            ->where('account_id', $this->account('1300')->id)
            ->sum('debit');
        $this->assertEquals(850000.00, (float) $debitAr);

        // Kredit pendapatan per akun jenis tagihan.
        $creditSpp = FinancialTransactionLine::query()
            ->where('finance_transaction_id', $journal->id)
            ->where('account_id', $this->account('4100')->id)
            ->sum('credit');
        $this->assertEquals(350000.00, (float) $creditSpp);
    }

    public function test_invoice_idempoten_per_billing(): void
    {
        $this->actingAsRole('finance-admin');
        $package = $this->package('PKT-INV-IDEM');
        $student = $this->student('S041');

        $resp = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id, 'period_key' => '2026-09', 'student_ids' => [$student->id],
        ])->assertOk();
        $billingId = $resp->json('data.billings.0');

        $a = $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated();
        $b = $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated();

        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame(1, FinanceInvoice::query()->where('billing_id', $billingId)->count());
        // Jurnal akrual hanya satu.
        $this->assertSame(1, FinancialTransaction::query()->where('reference_type', FinanceInvoice::class)->count());
    }

    // =================================================================
    // Pembatalan (§15, §51)
    // =================================================================

    public function test_batalkan_billing_membalik_jurnal_akrual(): void
    {
        $this->actingAsRole('finance-admin');
        $package = $this->package('PKT-CANCEL');
        $student = $this->student('S050');

        $resp = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id, 'period_key' => '2026-09', 'student_ids' => [$student->id],
        ])->assertOk();
        $billingId = $resp->json('data.billings.0');
        $invoiceId = $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated()->json('data.id');

        $journal = FinancialTransaction::query()->where('reference_type', FinanceInvoice::class)->where('reference_id', $invoiceId)->firstOrFail();

        $this->actingAsRole('finance-admin');
        $this->postJson("/api/v1/finance/billings/{$billingId}/cancel", ['reason' => 'Salah input'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        // Jurnal asli ditandai reversed + ada jurnal pembalik.
        $this->assertSame(FinancialTransaction::STATUS_REVERSED, $journal->fresh()->status);
        $this->assertNotNull($journal->fresh()->reversed_by_transaction_id);
        $this->assertSame(FinanceInvoice::STATUS_CANCELLED, FinanceInvoice::find($invoiceId)->status);
    }

    public function test_billing_untuk_pembatalan_wajib_punya_alasan(): void
    {
        $this->actingAsRole('finance-admin');
        $package = $this->package('PKT-CANCEL2');
        $student = $this->student('S051');

        $resp = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id, 'period_key' => '2026-09', 'student_ids' => [$student->id],
        ])->assertOk();
        $billingId = $resp->json('data.billings.0');

        $this->postJson("/api/v1/finance/billings/{$billingId}/cancel")->assertStatus(422);
    }

    // =================================================================
    // Billing manual (§13)
    // =================================================================

    public function test_billing_manual_dari_baris_bebas(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('S060');

        $resp = $this->postJson('/api/v1/finance/billings', [
            'customer_type' => 'student',
            'customer_id' => $student->id,
            'student_id' => $student->id,
            'due_date' => '2026-10-15',
            'items' => [
                ['fee_type_id' => $this->feeType('KEGIATAN')->id, 'description' => 'Kegiatan outing', 'amount' => 150000, 'quantity' => 1],
                ['description' => 'Denda perpustakaan', 'amount' => 25000, 'quantity' => 2],
            ],
        ])->assertCreated();

        $billing = FinanceBilling::find($resp->json('data.id'));
        // 150.000 + (25.000 × 2) = 200.000.
        $this->assertEquals(200000.00, (float) $billing->total);
        $this->assertSame('manual', $billing->source);
        $this->assertCount(2, $billing->items);
    }

    // =================================================================
    // Guard permission (§3)
    // =================================================================

    public function test_officer_boleh_generate_tapi_auditor_tidak(): void
    {
        $package = $this->package('PKT-PERM');
        $student = $this->student('S070');

        // finance-officer punya billing.create.
        $this->actingAsRole('finance-officer');
        $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id, 'period_key' => '2026-09', 'student_ids' => [$student->id],
        ])->assertOk();

        // auditor read-only → generate ditolak.
        $this->actingAsRole('auditor');
        $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id, 'period_key' => '2026-11', 'student_ids' => [$student->id],
        ])->assertStatus(403);
        $this->getJson('/api/v1/finance/billings?all=1')->assertOk();
    }
}
