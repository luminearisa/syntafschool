<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassStudent;
use App\Models\FinanceBilling;
use App\Models\FinanceCashAccount;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\FinanceReRegistration;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentHistory;
use App\Models\User;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Daftar Ulang / re-registrasi (§2–§7, §38, §45, §47 Phase 2) lewat HTTP.
 *
 * Yang diverifikasi: kelayakan (aktif/terdaftar/tunggakan), pratinjau, generasi
 * tunggal & massal, idempotensi (duplicate generation), diskon via paket, invoice
 * dibuat sebelum bayar, pembayaran sebagian/penuh menggerakkan status, completion
 * + aktivasi akademik, pembatalan, buka kembali, dan guard permission.
 */
class FinanceReRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $current;

    private AcademicYear $next;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceConfigSeeder::class);

        $this->current = AcademicYear::query()->firstOrCreate(
            ['name' => '2025/2026'],
            ['start_date' => '2025-07-01', 'end_date' => '2026-06-30', 'is_active' => true],
        );
        $this->next = AcademicYear::query()->firstOrCreate(
            ['name' => '2026/2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => false],
        );
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

    private function student(string $nis, bool $enrollCurrent = true): Student
    {
        $student = Student::query()->firstOrCreate(
            ['nis' => $nis],
            ['full_name' => 'Santri '.$nis, 'is_active' => true],
        );

        if ($enrollCurrent) {
            $class = SchoolClass::query()->firstOrCreate(
                ['code' => 'RMP-'.$nis],
                ['academic_year_id' => $this->current->id, 'name' => 'Rombel '.$nis, 'capacity' => 30, 'is_active' => true],
            );

            ClassStudent::query()->firstOrCreate(
                ['student_id' => $student->id, 'academic_year_id' => $this->current->id],
                ['school_class_id' => $class->id, 'status' => 'aktif'],
            );
        }

        return $student;
    }

    private function feeType(string $code): FinanceFeeType
    {
        return FinanceFeeType::query()->where('code', $code)->firstOrFail();
    }

    private function cash(): FinanceCashAccount
    {
        return FinanceCashAccount::query()->where('code', 'KAS-SEKOLAH')->firstOrFail();
    }

    /** Paket daftar ulang 2.000.000 (satu baris DAFTAR_ULANG). */
    private function reRegPackage(string $code = 'PKT-REG', int $amount = 2000000): FinanceFeePackage
    {
        $package = FinanceFeePackage::query()->firstOrCreate(
            ['code' => $code],
            ['name' => 'Daftar Ulang '.$code, 'boarding_type' => 'all', 'is_active' => true],
        );

        if ($package->items()->count() === 0) {
            $package->items()->createMany([
                ['fee_type_id' => $this->feeType('DAFTAR_ULANG')->id, 'amount' => $amount, 'frequency' => 'once', 'is_active' => true],
            ]);
        }

        return $package;
    }

    /** Config generate standar: tahun eksplisit + paket. */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'current_academic_year_id' => $this->current->id,
            'next_academic_year_id' => $this->next->id,
            'fee_package_id' => $this->reRegPackage()->id,
            'due_date' => Carbon::today()->addDays(30)->toDateString(),
        ], $overrides);
    }

    private function payInvoice(FinanceInvoice $invoice, float $amount): void
    {
        $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'method' => 'cash',
            'cash_account_id' => $this->cash()->id,
        ])->assertCreated();
    }

    // =================================================================
    // Kelayakan & pratinjau (§4, §5)
    // =================================================================

    public function test_preview_memuat_santri_aktif_dan_mengecualikan_yang_tidak_loyal(): void
    {
        $this->actingAsRole('finance-admin');

        $eligible = $this->student('RG001');
        $inactive = $this->student('RG002');
        $inactive->update(['is_active' => false]);
        $notEnrolled = $this->student('RG003', enrollCurrent: false);

        $resp = $this->postJson('/api/v1/finance/re-registration/preview', $this->config())->assertOk();

        $ids = collect($resp->json('data.students'))->pluck('student_id')->all();
        $this->assertContains($eligible->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
        $this->assertNotContains($notEnrolled->id, $ids);
        $this->assertSame(1, (int) $resp->json('data.eligible'));
        $this->assertSame(1, (int) $resp->json('data.will_generate'));
        $this->assertSame(0, (int) $resp->json('data.already_generated'));
    }

    public function test_santri_dengan_tunggakan_dikecualikan_kecuali_kebijakan_mengizinkan(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('RG010');

        // Tunggakan lama (bukan invoice daftar ulang) → harus menghalangi.
        $oldPackage = FinanceFeePackage::query()->firstOrCreate(
            ['code' => 'PKT-TUNGGAK'],
            ['name' => 'Tunggakan', 'boarding_type' => 'all', 'is_active' => true],
        );
        if ($oldPackage->items()->count() === 0) {
            $oldPackage->items()->createMany([
                ['fee_type_id' => $this->feeType('SPP')->id, 'amount' => 350000, 'frequency' => 'monthly', 'is_active' => true],
            ]);
        }
        $billingId = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $oldPackage->id,
            'period_key' => '2026-08',
            'student_ids' => [$student->id],
            'due_date' => '2026-08-30',
        ])->assertOk()->json('data.billings.0');
        $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated();

        // Kebijakan ketat (default): tunggakan menghalangi daftar ulang.
        $this->postJson('/api/v1/finance/re-registration/preview', $this->config())
            ->assertOk()
            ->assertJsonPath('data.eligible', 0);

        // Longgarkan kebijakan → santri kembali eligible (§4: jangan hardcode).
        $this->patchJson('/api/v1/finance/settings', ['allow_re_registration_with_outstanding' => true])
            ->assertOk();

        $this->postJson('/api/v1/finance/re-registration/preview', $this->config())
            ->assertOk()
            ->assertJsonPath('data.eligible', 1);
    }

    // =================================================================
    // Generasi (§5, §12, §24)
    // =================================================================

    public function test_generate_tunggal_membuat_billing_invoice_dan_pendaftaran(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('RG020');

        $resp = $this->postJson('/api/v1/finance/re-registration/generate', $this->config(['student_id' => $student->id]))
            ->assertCreated();

        $regId = $resp->json('data.id');
        $this->assertNotNull($regId);
        $this->assertSame('unpaid', $resp->json('data.status'));
        $this->assertEquals(2000000.00, (float) $resp->json('data.amount'));
        $this->assertNotNull($resp->json('data.billing_id'));
        $this->assertNotNull($resp->json('data.invoice_id'));

        $reg = FinanceReRegistration::find($regId);
        $this->assertSame(FinanceReRegistration::STATUS_UNPAID, $reg->status);
        $this->assertNotNull($reg->billing_id);
        $this->assertNotNull($reg->invoice_id);

        // Invoice dibuat SEBELUM pembayaran (§2) dan belum lunas.
        $invoice = FinanceInvoice::find($reg->invoice_id);
        $this->assertSame(FinanceInvoice::STATUS_ISSUED, $invoice->status);
        $this->assertEquals(2000000.00, (float) $invoice->remaining_amount);
    }

    public function test_generate_massal_idempoten_tidak_menduplikasi(): void
    {
        $this->actingAsRole('finance-admin');
        $this->student('RG030');
        $this->student('RG031');

        $first = $this->postJson('/api/v1/finance/re-registration/generate', $this->config())->assertOk();
        $this->assertSame(2, (int) $first->json('data.generated'));
        $this->assertSame(0, (int) $first->json('data.skipped'));

        // Jalankan lagi: tidak boleh ada baris kedua, semuanya dilewati.
        $second = $this->postJson('/api/v1/finance/re-registration/generate', $this->config())->assertOk();
        $this->assertSame(0, (int) $second->json('data.generated'));
        $this->assertSame(2, (int) $second->json('data.skipped'));

        $this->assertSame(2, FinanceReRegistration::query()->where('next_academic_year_id', $this->next->id)->count());
        $this->assertSame(2, FinanceBilling::query()->where('source', 'package')->count());
    }

    public function test_generate_manual_dengan_nominal_tanpa_paket(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('RG040');

        $resp = $this->postJson('/api/v1/finance/re-registration/generate', $this->config([
            'student_id' => $student->id,
            'fee_package_id' => null,
            'amount' => 1500000,
        ]))->assertCreated();

        $this->assertEquals(1500000.00, (float) $resp->json('data.amount'));
        $reg = FinanceReRegistration::find($resp->json('data.id'));
        $this->assertSame('manual', $reg->billing->source);
    }

    public function test_diskon_paket_tercermin_pada_nominal_pendaftaran(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('RG050');

        // Paket 2.000.000 dengan diskon global 500.000 (§17) → invoice 1.500.000.
        \App\Models\FinanceDiscount::query()->create([
            'name' => 'Diskon Daftar Ulang',
            'type' => 'fixed',
            'value' => 500000,
            'fee_type_id' => null,
            'student_id' => null,
            'program_id' => null,
            'start_date' => Carbon::today()->subDay(),
            'end_date' => Carbon::today()->addYear(),
            'is_active' => true,
        ]);

        $resp = $this->postJson('/api/v1/finance/re-registration/generate', $this->config(['student_id' => $student->id]))
            ->assertCreated();

        $this->assertEquals(1500000.00, (float) $resp->json('data.amount'));
        $this->assertEquals(500000.00, (float) $resp->json('data.discount_amount'));
    }

    // =================================================================
    // Pembayaran → status (§6, §32)
    // =================================================================

    public function test_pembayaran_sebagian_lalu_lunas_menggerakkan_status(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('RG060');

        $regId = $this->postJson('/api/v1/finance/re-registration/generate', $this->config(['student_id' => $student->id]))
            ->assertCreated()->json('data.id');
        $reg = FinanceReRegistration::find($regId);
        $invoice = FinanceInvoice::find($reg->invoice_id);

        // Bayar sebagian 1.000.000 → partial.
        $this->payInvoice($invoice, 1000000);
        $reg->refresh();
        $this->assertSame(FinanceReRegistration::STATUS_PARTIAL, $reg->status);
        $this->assertEquals(1000000.00, (float) $reg->paid_amount);

        // Lunasi sisa 1.000.000 → paid.
        $this->payInvoice($invoice->refresh(), 1000000);
        $reg->refresh();
        $this->assertSame(FinanceReRegistration::STATUS_PAID, $reg->status);
        $this->assertEquals(2000000.00, (float) $reg->paid_amount);
    }

    // =================================================================
    // Completion + aktivasi akademik (§7)
    // =================================================================

    public function test_complete_sebelum_lunas_ditolak(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('RG070');

        $regId = $this->postJson('/api/v1/finance/re-registration/generate', $this->config(['student_id' => $student->id]))
            ->assertCreated()->json('data.id');

        $this->postJson("/api/v1/finance/re-registration/{$regId}/complete")
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice');
    }

    public function test_complete_setelah_lunas_mengaktifkan_tahun_berikutnya(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('RG080');

        $regId = $this->postJson('/api/v1/finance/re-registration/generate', $this->config(['student_id' => $student->id]))
            ->assertCreated()->json('data.id');
        $reg = FinanceReRegistration::find($regId);
        $this->payInvoice(FinanceInvoice::find($reg->invoice_id), 2000000);

        $resp = $this->postJson("/api/v1/finance/re-registration/{$regId}/complete", [
            'history_status' => 'naik',
        ])->assertOk();

        $this->assertSame('completed', $resp->json('data.status'));
        $this->assertTrue((bool) $resp->json('data.activated'));

        $reg->refresh();
        $this->assertSame(FinanceReRegistration::STATUS_COMPLETED, $reg->status);
        $this->assertTrue($reg->activated);
        $this->assertNotNull($reg->completed_at);

        // Aktivasi akademik (§7): riwayat tahun berikutnya terbentuk.
        $this->assertDatabaseHas('student_histories', [
            'student_id' => $student->id,
            'academic_year_id' => $this->next->id,
            'status' => 'naik',
        ]);
    }

    // =================================================================
    // Batal & buka kembali (§5, §6)
    // =================================================================

    public function test_cancel_tanpa_pembayaran_membatalkan_billing_dan_pendaftaran(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('RG090');

        $regId = $this->postJson('/api/v1/finance/re-registration/generate', $this->config(['student_id' => $student->id]))
            ->assertCreated()->json('data.id');
        $reg = FinanceReRegistration::find($regId);

        $this->postJson("/api/v1/finance/re-registration/{$regId}/cancel", ['reason' => 'Salah input'])
            ->assertOk();

        $reg->refresh();
        $this->assertSame(FinanceReRegistration::STATUS_CANCELLED, $reg->status);
        $this->assertSame(FinanceBilling::STATUS_CANCELLED, $reg->billing->status);
    }

    public function test_cancel_setelah_pembayaran_ditolak(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('RG100');

        $regId = $this->postJson('/api/v1/finance/re-registration/generate', $this->config(['student_id' => $student->id]))
            ->assertCreated()->json('data.id');
        $reg = FinanceReRegistration::find($regId);
        $this->payInvoice(FinanceInvoice::find($reg->invoice_id), 2000000);

        $this->postJson("/api/v1/finance/re-registration/{$regId}/cancel", ['reason' => 'Batal'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('re_registration');
    }

    public function test_reopen_setelah_cancel_memungkinkan_generate_ulang(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('RG110');

        $regId = $this->postJson('/api/v1/finance/re-registration/generate', $this->config(['student_id' => $student->id]))
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/finance/re-registration/{$regId}/cancel", ['reason' => 'Batal sementara'])->assertOk();

        $this->postJson("/api/v1/finance/re-registration/{$regId}/reopen")->assertOk();
        $reg = FinanceReRegistration::find($regId);
        $this->assertSame(FinanceReRegistration::STATUS_ELIGIBLE, $reg->status);
        $this->assertNull($reg->billing_id);

        // Generate ulang menghidupkan baris yang sama (tidak duplikat).
        $this->postJson('/api/v1/finance/re-registration/generate', $this->config(['student_id' => $student->id]))
            ->assertCreated();
        $this->assertSame(1, FinanceReRegistration::query()->where('student_id', $student->id)->count());
        $this->assertSame(FinanceReRegistration::STATUS_UNPAID, $reg->refresh()->status);
    }

    // =================================================================
    // Permission (§44)
    // =================================================================

    public function test_siswa_tidak_boleh_melihat_daftar_ulang(): void
    {
        $this->actingAsRole('finance-admin');
        $this->student('RG120');

        $this->actingAsRole('siswa');
        $this->getJson('/api/v1/finance/re-registration')->assertForbidden();
    }

    public function test_officer_boleh_generate_tapi_tidak_boleh_complete(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('RG130');
        $regId = $this->postJson('/api/v1/finance/re-registration/generate', $this->config(['student_id' => $student->id]))
            ->assertCreated()->json('data.id');
        $reg = FinanceReRegistration::find($regId);
        $this->payInvoice(FinanceInvoice::find($reg->invoice_id), 2000000);

        // finance-officer punya re_registration.generate + view, bukan manage.
        $this->actingAsRole('finance-officer');
        $this->getJson('/api/v1/finance/re-registration')->assertOk();
        $this->postJson("/api/v1/finance/re-registration/{$regId}/complete")->assertForbidden();

        // finance-manager punya manage → boleh menyelesaikan.
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/re-registration/{$regId}/complete")->assertOk();
    }
}
