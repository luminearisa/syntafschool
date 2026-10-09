<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassStudent;
use App\Models\FinanceCashAccount;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\FinancePayment;
use App\Models\Guardian;
use App\Models\PpdbParentAccount;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\FinanceSettingsService;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Portal orang tua Keuangan (§9) + ringkasan finansial santri (§10) Phase 5.
 *
 * Yang dijaga di sini bukan angkanya — itu milik service pembayaran dan sudah
 * punya tes sendiri — melainkan DUA hal:
 *
 *  1. kepemilikan (§9 "Data anak tidak boleh tercampur"): daftar anak hanya
 *     berisi anak login, dan santri milik orang lain dibalas 404 (bukan 403,
 *     agar portal tidak mengonfirmasi keberadaan id santri);
 *  2. portal membaca lewat endpoint dan resource yang sama dengan layar admin,
 *     jadi ia tidak melahirkan bentuk data atau angka kedua.
 *
 * Semua jalur lewat HTTP: tidak ada service yang dipanggil langsung, karena
 * justru middleware + `assertChild()`-nya yang menjadi objek uji.
 */
class FinancePortalTest extends TestCase
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

        // Portal bertanya "hari ini tanggal berapa" untuk jendela bulan berjalan
        // dan tagihan lewat tempo.
        Carbon::setTestNow('2026-09-27 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // =================================================================
    // Kepemilikan anak (§9)
    // =================================================================

    public function test_portal_hanya_memuat_anak_milik_login(): void
    {
        $this->actingAsRole('finance-admin');

        $milikA = $this->family('PRT001');
        $milikB = $this->family('PRT002');
        $orangLain = $this->family('PRT003');

        // Satu akun wali, dua anak: `ppdb_parent_accounts.user_id` unik, jadi
        // anak kedua ditautkan lewat guardian akun itu juga — bukan akun kedua.
        DB::table('student_guardians')->insert([
            'student_id' => $milikB['student']->id,
            'guardian_id' => $milikA['guardian']->id,
            'is_primary' => false,
        ]);

        $invoiceA = $this->issuedInvoice($milikA['student'], '2026-09', 'PKT-PT-A');
        $this->issuedInvoice($milikB['student'], '2026-09', 'PKT-PT-A');
        $this->issuedInvoice($orangLain['student'], '2026-09', 'PKT-PT-A');

        // Wali: tanpa satu pun permission finance.
        Sanctum::actingAs($milikA['parent_user'], ['*']);

        $children = $this->getJson('/api/v1/finance/portal/children')
            ->assertOk()
            ->json('data');

        $ids = array_column($children, 'student_id');
        sort($ids);

        $this->assertSame([$milikA['student']->id, $milikB['student']->id], array_map('intval', $ids));
        // Nominal bulat datang dari JSON sebagai int — bandingkan sebagai float.
        $this->assertSame(850000.0, (float) $children[0]['outstanding_total']);

        // Tagihan tiap anak terpisah: layar anak A hanya memuat invoice A.
        $rows = $this->getJson('/api/v1/finance/portal/children/'.$milikA['student']->id.'/invoices')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($invoiceA->invoice_number, $rows[0]['invoice_number']);

        // Santri milik orang lain: 404, bukan 403 dan bukan daftar kosong.
        $this->getJson('/api/v1/finance/portal/children/'.$orangLain['student']->id.'/invoices')
            ->assertNotFound();
        $this->getJson('/api/v1/finance/portal/children/'.$orangLain['student']->id.'/summary')
            ->assertNotFound();

        // Dan akun yang sama sekali tidak punya anak.
        $solo = User::factory()->create(['email' => 'solo@schoolerp.test', 'is_active' => true]);
        Sanctum::actingAs($solo, ['*']);

        $this->assertSame([], $this->getJson('/api/v1/finance/portal/children')->assertOk()->json('data'));
        $this->getJson('/api/v1/finance/portal/children/'.$milikA['student']->id.'/invoices')->assertNotFound();
    }

    public function test_portal_butuh_login_dan_tidak_menyediakan_endpoint_tulis(): void
    {
        $this->getJson('/api/v1/finance/portal/children')->assertUnauthorized();

        // Portal hanya baca (§9): tidak ada satu pun route POST/PUT di bawahnya,
        // jadi mencoba menulis dibalas 405 — bukan 403 yang menyiratkan portal
        // punya aksi tulis yang haknya kurang.
        $this->postJson('/api/v1/finance/portal/children', [])->assertMethodNotAllowed();
    }

    // =================================================================
    // Ringkasan finansial (§10)
    // =================================================================

    public function test_ringkasan_keuangan_dipecah_per_jendela_waktu(): void
    {
        $this->actingAsRole('finance-admin');
        $family = $this->family('PRT010');
        $student = $family['student'];

        // Bulan berjalan: SPP 850rb dibayar sebagian.
        $september = $this->issuedInvoice($student, '2026-09', 'PKT-PT-B');
        $this->pay($september, 500000);

        // Bulan lain dalam tahun ajaran yang sama (agustus 2025) — harus keluar
        // dari jendela bulan berjalan tetapi tetap masuk tahun ajaran.
        $agustus = $this->issuedInvoice($student, '2025-08', 'PKT-PT-B');
        $agustus->forceFill(['issue_date' => Carbon::create(2025, 8, 5), 'due_date' => Carbon::create(2025, 8, 15)])
            ->save();

        // Kelebihan bayar ditahan sebagai deposit (§19) → credit balance.
        app(FinanceSettingsService::class)->update(['overpayment_policy' => 'credit_balance']);
        $juli = $this->issuedInvoice($student, '2026-07', 'PKT-PT-B');
        $juli->forceFill(['issue_date' => Carbon::create(2026, 7, 5)])->save();
        $this->pay($juli, 900000);

        Sanctum::actingAs($family['parent_user'], ['*']);

        $summary = $this->getJson('/api/v1/finance/portal/children/'.$student->id.'/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame($student->id, $summary['student_id']);
        $this->assertSame('Santri PRT010', $summary['full_name']);

        $totals = $summary['totals'];
        // Nominal bulat datang dari JSON sebagai int, jadi semuanya dibandingkan
        // sebagai float.
        $this->assertSame(2550000.0, (float) $totals['billing_total']);
        // 500rb September + 850rb Juli yang lunas penuh; Agustus belum dibayar.
        $this->assertSame(1350000.0, (float) $totals['paid_total']);
        $this->assertSame(50000.0, (float) $totals['credit_balance']);
        $this->assertSame(3, $totals['invoice_count']);

        // Agustus sudah lewat jatuh tempo dan belum lunas → penyumbang overdue.
        $this->assertSame(1, $totals['overdue_invoice_count']);
        $this->assertSame(850000.0, (float) $totals['overdue_total']);
        $this->assertSame(1200000.0, (float) $totals['outstanding_total']);

        // Jendela (§10): bulan berjalan hanya September.
        $this->assertSame(850000.0, (float) $summary['periods']['current_month']['billing_total']);
        $this->assertSame(500000.0, (float) $summary['periods']['current_month']['paid_total']);
        $this->assertSame('September 2026', $summary['periods']['current_month']['label']);

        // Tahun ajaran aktif 2025/2026 (Jul 2025–Jun 2026) hanya memuat Agustus;
        // invoice Juli & September 2026 sudah tahun ajaran berikutnya.
        $this->assertSame('2025/2026', $summary['periods']['current_academic_year']['label']);
        $this->assertSame(850000.0, (float) $summary['periods']['current_academic_year']['billing_total']);

        // Lifetime = angka totals.
        $this->assertSame(2550000.0, (float) $summary['periods']['lifetime']['billing_total']);
    }

    // =================================================================
    // Pemecahan layar §9
    // =================================================================

    public function test_tagihan_dipisah_per_jenis_dan_daftar_ulang_punya_layarnya_sendiri(): void
    {
        $this->actingAsRole('finance-admin');
        $family = $this->family('PRT020', enrolled: true);
        $student = $family['student'];

        // Paket uji punya dua jenis biaya: SPP dan uang asrama.
        $this->issuedInvoice($student, '2026-09', 'PKT-PT-C');

        // Daftar ulang menerbitkan invoice sendiri (§4).
        $this->postJson('/api/v1/finance/re-registration/generate', $this->reRegistrationConfig([
            'student_id' => $student->id,
        ]))->assertCreated();

        Sanctum::actingAs($family['parent_user'], ['*']);
        $base = '/api/v1/finance/portal/children/'.$student->id.'/invoices';

        // "SPP" dan "Tagihan Lain" adalah filter jenis biaya, bukan golongan
        // yang dikarang portal: kodenya yang menentukan.
        $this->assertCount(1, $this->getJson($base.'?fee_type=SPP')->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson($base.'?fee_type=UANG_ASRAMA')->assertOk()->json('data'));
        $this->assertCount(0, $this->getJson($base.'?fee_type=UJIAN')->assertOk()->json('data'));

        // Invoice daftar ulang tidak menumpuk di daftar tagihan...
        $biasa = $this->getJson($base)->assertOk()->json('data');
        $this->assertCount(1, $biasa);

        // ...tapi tetap terbaca bila diminta, dan punya layarnya sendiri.
        $semua = $this->getJson($base.'?include_re_registration=1')->assertOk()->json('data');
        $this->assertCount(2, $semua);

        $registrasi = $this->getJson('/api/v1/finance/portal/children/'.$student->id.'/re-registrations')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $registrasi);
        $this->assertSame(2000000.0, (float) $registrasi[0]['amount']);

        // Format bulan yang salah dibaca "tanpa filter", bukan error.
        $this->assertCount(1, $this->getJson($base.'?month=bukan-tanggal')->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson($base.'?month=2026-09')->assertOk()->json('data'));
        $this->assertCount(0, $this->getJson($base.'?month=2025-01')->assertOk()->json('data'));
    }

    public function test_pembayaran_kuitansi_piutang_dan_cicilan_anak_terbaca(): void
    {
        $this->actingAsRole('finance-admin');
        $family = $this->family('PRT030');
        $student = $family['student'];

        $lunas = $this->issuedInvoice($student, '2026-09', 'PKT-PT-D');
        $this->pay($lunas, 850000);

        $cicil = $this->issuedInvoice($student, '2026-10', 'PKT-PT-D', Carbon::create(2026, 11, 25)->toDateString());
        app(FinanceSettingsService::class)->update(['payment_plan_enabled' => true]);
        $this->postJson('/api/v1/finance/payment-plans', [
            'invoice_id' => $cicil->id,
            'installment_count' => 3,
            'start_date' => Carbon::create(2026, 10, 5)->toDateString(),
        ])->assertCreated();

        Sanctum::actingAs($family['parent_user'], ['*']);

        // Pembayaran + riwayat + kuitansi (§9): satu pembayaran lunas = satu kuitansi.
        $payments = $this->getJson('/api/v1/finance/portal/children/'.$student->id.'/payments')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $payments);
        $this->assertSame(FinancePayment::STATUS_PAID, $payments[0]['status']);
        $this->assertSame(850000.0, (float) $payments[0]['amount']);

        $receipts = $this->getJson('/api/v1/finance/portal/children/'.$student->id.'/receipts')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $receipts);
        $this->assertNotEmpty($receipts[0]['receipt_number']);

        // Piutang: hanya invoice Oktober yang belum dibayar.
        $receivables = $this->getJson('/api/v1/finance/portal/children/'.$student->id.'/receivables')
            ->assertOk();
        $rows = $receivables->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($cicil->invoice_number, $rows[0]['invoice_number']);
        $this->assertSame(850000.0, (float) $receivables->json('meta.summary.total_receivable'));

        // Rencana pembayaran anak: 3 angsuran, belum ada yang terbayar.
        $plans = $this->getJson('/api/v1/finance/portal/children/'.$student->id.'/payment-plans')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $plans);
        $this->assertCount(3, $plans[0]['installments']);
        $this->assertSame(283333.33, (float) $plans[0]['installments'][0]['amount']);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /** @return array{student: Student, student_user: User, guardian: Guardian, parent_user: User} */
    private function family(string $nis, bool $enrolled = false): array
    {
        $student = Student::query()->firstOrCreate(
            ['nis' => $nis],
            ['full_name' => 'Santri '.$nis, 'is_active' => true],
        );

        $studentUser = User::factory()->create([
            'name' => 'Akun '.$nis,
            'email' => strtolower($nis).'@schoolerp.test',
            'is_active' => true,
            'userable_type' => Student::class,
            'userable_id' => $student->id,
        ]);

        $guardian = Guardian::query()->create([
            'full_name' => 'Wali '.$nis,
            'email' => strtolower($nis).'-wali@schoolerp.test',
        ]);

        DB::table('student_guardians')->insert([
            'student_id' => $student->id,
            'guardian_id' => $guardian->id,
            'is_primary' => true,
        ]);

        $parentUser = User::factory()->create([
            'name' => 'Wali '.$nis,
            'email' => strtolower($nis).'-parent@schoolerp.test',
            'is_active' => true,
        ]);

        PpdbParentAccount::query()->create([
            'user_id' => $parentUser->id,
            'guardian_id' => $guardian->id,
            'account_status' => PpdbParentAccount::STATUS_ACTIVE,
            'verified_at' => Carbon::now(),
        ]);

        if ($enrolled) {
            $class = SchoolClass::query()->firstOrCreate(
                ['code' => 'RMP-'.$nis],
                ['academic_year_id' => $this->current->id, 'name' => 'Rombel '.$nis, 'capacity' => 30, 'is_active' => true],
            );

            ClassStudent::query()->firstOrCreate(
                ['student_id' => $student->id, 'academic_year_id' => $this->current->id],
                ['school_class_id' => $class->id, 'status' => 'aktif'],
            );
        }

        return ['student' => $student, 'student_user' => $studentUser, 'guardian' => $guardian, 'parent_user' => $parentUser];
    }

    /** Billing tergenerate + invoice terbit periode `Y-m`. */
    private function issuedInvoice(Student $student, string $period, string $pkg, ?string $due = null): FinanceInvoice
    {
        $billingId = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $this->package($pkg)->id,
            'period_key' => $period,
            'student_ids' => [$student->id],
            'due_date' => $due ?? Carbon::create(2026, 9, 30)->toDateString(),
        ])->assertOk()->json('data.billings.0');

        return FinanceInvoice::findOrFail(
            (int) $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated()->json('data.id')
        );
    }

    /** Pembayaran tunai langsung lunas. */
    private function pay(FinanceInvoice $invoice, float $amount): void
    {
        $this->actingAsRole('finance-admin');

        $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'method' => 'cash',
            'cash_account_id' => FinanceCashAccount::query()->where('code', 'KAS-SEKOLAH')->value('id'),
        ])->assertCreated()->assertJsonPath('data.status', FinancePayment::STATUS_PAID);
    }

    /** Paket dua jenis biaya: SPP 350rb + uang asrama 500rb = 850rb. */
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

    private function feeType(string $code): FinanceFeeType
    {
        return FinanceFeeType::query()->where('code', $code)->firstOrFail();
    }

    private function reRegistrationConfig(array $overrides = []): array
    {
        $package = FinanceFeePackage::query()->firstOrCreate(
            ['code' => 'PKT-PT-REG'],
            ['name' => 'Daftar Ulang Portal', 'boarding_type' => 'all', 'is_active' => true],
        );

        if ($package->items()->count() === 0) {
            $package->items()->createMany([
                ['fee_type_id' => $this->feeType('DAFTAR_ULANG')->id, 'amount' => 2000000, 'frequency' => 'once', 'is_active' => true],
            ]);
        }

        return array_merge([
            'current_academic_year_id' => $this->current->id,
            'next_academic_year_id' => $this->next->id,
            'fee_package_id' => $package->id,
            'due_date' => Carbon::today()->addDays(30)->toDateString(),
        ], $overrides);
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
}
