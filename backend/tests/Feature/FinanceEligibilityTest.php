<?php

namespace Tests\Feature;

use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\FinancialEligibilityService;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kelayakan finansial santri (§11 Phase 5).
 *
 * Tiga hal yang diuji, dan ketiganya adalah tuntutan langsung §11:
 *
 *  1. tiga putusan bisa dibedakan — ELIGIBLE, REQUIRES_APPROVAL, NOT_ELIGIBLE —
 *     dan bukan hanya "lunas / belum lunas";
 *  2. tidak ada aturan yang di-hardcode: setiap perubahan putusan datang dari
 *     Settings, bukan dari mengubah kode;
 *  3. Finance tidak menyentuh status akademik santri — pemeriksaan dibaca
 *     bolak-balik dan baris `students` tetap identik.
 *
 * Semua lewat HTTP, termasuk yang bisa dipanggil langsung dari service: justru
 * middleware permission dan bentuk respons yang menjadi objek uji.
 */
class FinanceEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceConfigSeeder::class);

        Carbon::setTestNow('2026-09-27 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // =================================================================
    // Tiga putusan (§11)
    // =================================================================

    public function test_tiga_putusan_terbit_dari_aturan_bawaan_yang_berbeda(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('ELG001');

        // Bersih: semua aksi layak.
        $bersih = $this->getJson('/api/v1/finance/eligibility/'.$student->id)->assertOk()->json('data.results');
        $this->assertSame(FinancialEligibilityService::RESULT_ELIGIBLE, $bersih['exam']['result']);
        $this->assertSame(FinancialEligibilityService::RESULT_ELIGIBLE, $bersih['graduation_clearance']['result']);

        // Satu invoice lewat jatuh tempo, belum dibayar.
        $this->issuedInvoice($student, '2026-08', Carbon::create(2026, 8, 30)->toDateString());

        $hasil = $this->getJson('/api/v1/finance/eligibility/'.$student->id)->assertOk()->json('data.results');

        // Bawaan §11: ujian butuh persetujuan, kelulusan ditahan, perpustakaan
        // tidak ikut ditahan Finance (dendanya milik modul Library).
        $this->assertSame(FinancialEligibilityService::RESULT_REQUIRES_APPROVAL, $hasil['exam']['result']);
        $this->assertSame(FinancialEligibilityService::RESULT_NOT_ELIGIBLE, $hasil['graduation_clearance']['result']);
        $this->assertSame(FinancialEligibilityService::RESULT_ELIGIBLE, $hasil['library_clearance']['result']);

        // Putusan "butuh persetujuan" menyebut siapa yang berwenang, supaya
        // layar pemanggil tidak perlu menebak role mana yang harus dimintai.
        $this->assertSame('finance.eligibility.approve', $hasil['exam']['approval_permission']);
        $this->assertNull($hasil['graduation_clearance']['approval_permission']);

        // §11 + §12: alasannya dirinci, bukan "Belum Lunas".
        $line = $hasil['graduation_clearance']['blocking'][0];
        $this->assertSame(1, $hasil['graduation_clearance']['blocking_count']);
        $this->assertSame(850000.0, (float) $hasil['graduation_clearance']['blocking_total']);
        $this->assertNotEmpty($line['invoice_number']);
        $this->assertSame('2026-08-30', $line['due_date']);
        $this->assertSame(850000.0, (float) $line['remaining_amount']);
        $this->assertSame(850000.0, (float) $line['total']);
        $this->assertSame(FinanceInvoice::STATUS_ISSUED, $line['status']);
        $this->assertStringContainsString('SPP', $line['description']);
        $this->assertStringContainsString('+1 baris lain', $line['description']);
        $this->assertStringContainsString('850.000', $hasil['graduation_clearance']['message']);
    }

    public function test_santri_tanpa_tagihan_lewat_tempo_layak_walau_punya_tagihan_berjalan(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('ELG002');

        // Jatuh tempo masih sebulan lagi: bukan tunggakan, hanya tagihan berjalan.
        $this->issuedInvoice($student, '2026-09', Carbon::create(2026, 10, 30)->toDateString());

        $exam = $this->getJson('/api/v1/finance/eligibility/'.$student->id.'/exam')
            ->assertOk()
            ->json('data');

        $this->assertSame(FinancialEligibilityService::RESULT_ELIGIBLE, $exam['result']);
        $this->assertSame(0, $exam['blocking_count']);
        // Angka piutangnya tetap dilaporkan: "layak" tidak berarti "tidak ada tagihan".
        $this->assertSame(850000.0, (float) $exam['outstanding']['total_receivable']);
        $this->assertSame(0.0, (float) $exam['outstanding']['total_overdue']);
    }

    // =================================================================
    // Aturan dari konfigurasi, bukan kode (§11)
    // =================================================================

    public function test_putusan_berubah_hanya_oleh_konfigurasi(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('ELG010');
        $this->issuedInvoice($student, '2026-08', Carbon::create(2026, 8, 30)->toDateString());

        // Bawaan: ujian butuh persetujuan, ijazah ikut butuh.
        $this->assertSame('approve', $this->ruleOf($student->id, 'exam'));
        $this->assertSame('approve', $this->ruleOf($student->id, 'certificate'));

        // Sekolah melarang keras untuk ijazah dan membebaskan ujian.
        $this->patchJson('/api/v1/finance/settings', [
            'eligibility_rules' => '{"exam":"allow","certificate":"block"}',
        ])->assertOk();

        $this->assertSame(FinancialEligibilityService::RESULT_ELIGIBLE, $this->check($student->id, 'exam')['result']);
        $this->assertSame(FinancialEligibilityService::RESULT_NOT_ELIGIBLE, $this->check($student->id, 'certificate')['result']);

        // Sakelar utama: aturan per aksi tidak ditanyakan lagi.
        $this->patchJson('/api/v1/finance/settings', [
            'eligibility_rules' => '{"graduation_clearance":"block"}',
            'eligibility_enabled' => false,
        ])->assertOk();

        $this->assertSame(FinancialEligibilityService::RESULT_ELIGIBLE, $this->check($student->id, 'graduation_clearance')['result']);

        // Nilai yang tidak dikenal tidak dipakai diam-diam: dilaporkan sebagai
        // peringatan supaya Settings bisa memperbaikinya.
        $this->patchJson('/api/v1/finance/settings', [
            'eligibility_enabled' => true,
            'eligibility_rules' => '{"exam":"blok","upacara":"allow"}',
        ])->assertOk();

        $configuration = $this->getJson('/api/v1/finance/eligibility/rules')->assertOk()->json('data');

        $this->assertSame('approve', $configuration['actions']['exam']['rule']);
        $this->assertFalse($configuration['actions']['exam']['overridden']);
        $this->assertCount(2, $configuration['warnings']);
        $this->assertSame('exam', $configuration['warnings'][0]['action']);
        $this->assertSame('Aksi tidak dikenal.', $configuration['warnings'][1]['reason']);
    }

    public function test_basis_dan_ambang_membaca_kebijakan_sekolah(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('ELG011');

        // Telat 1 hari, 850rb.
        $this->issuedInvoice($student, '2026-09', Carbon::create(2026, 9, 26)->toDateString());

        // Masa tenggang 3 hari → belum dianggap telat.
        $this->assertSame(1, $this->check($student->id, 'exam')['blocking_count']);
        $this->patchJson('/api/v1/finance/settings', ['eligibility_grace_days' => 3])->assertOk();
        $verdict = $this->check($student->id, 'exam');
        $this->assertSame(0, $verdict['blocking_count']);
        $this->assertSame(3, $verdict['thresholds']['grace_days']);
        $this->assertSame(FinancialEligibilityService::RESULT_ELIGIBLE, $verdict['result']);

        // Ambang nominal: tunggakan di bawah nilai minimum tidak menghentikan,
        // tetapi barisnya tetap dilaporkan.
        $this->patchJson('/api/v1/finance/settings', [
            'eligibility_grace_days' => 0,
            'eligibility_min_outstanding' => 1000000,
        ])->assertOk();

        $verdict = $this->check($student->id, 'graduation_clearance');
        $this->assertSame(1, $verdict['blocking_count']);
        $this->assertFalse($verdict['thresholds']['material']);
        $this->assertSame(FinancialEligibilityService::RESULT_ELIGIBLE, $verdict['result']);

        // Basis `outstanding`: tagihan yang BELUM jatuh tempo ikut menahan.
        $lain = $this->student('ELG012');
        $this->issuedInvoice($lain, '2026-10', Carbon::create(2026, 10, 30)->toDateString());

        $this->assertSame(FinancialEligibilityService::RESULT_ELIGIBLE, $this->check($lain->id, 'graduation_clearance')['result']);

        $this->patchJson('/api/v1/finance/settings', [
            'eligibility_min_outstanding' => 0,
            'eligibility_basis' => 'outstanding',
        ])->assertOk();

        $verdict = $this->check($lain->id, 'graduation_clearance');
        $this->assertSame('outstanding', $verdict['basis']);
        $this->assertSame(FinancialEligibilityService::RESULT_NOT_ELIGIBLE, $verdict['result']);

        // Basis yang tidak dikenal ditolak Settings, bukan dipakai separuh.
        $this->patchJson('/api/v1/finance/settings', ['eligibility_basis' => 'setengah-bayar'])
            ->assertStatus(422);
    }

    // =================================================================
    // Daftar ulang: satu kunci, satu makna
    // =================================================================

    public function test_daftar_ulang_tetap_dipimpin_kunci_fase_sebelumnya(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('ELG020');

        // Tagihan berjalan yang belum jatuh tempo.
        $this->issuedInvoice($student, '2026-09', Carbon::create(2026, 10, 30)->toDateString());

        // Kebijakan ketat Phase 2 berarti "tunggakan berapapun": kunci global
        // `eligibility_basis` (overdue) sengaja tidak mengubah makna itu.
        $ketat = $this->check($student->id, 're_registration');
        $this->assertSame('block', $ketat['rule']);
        $this->assertSame('outstanding', $ketat['basis']);
        $this->assertSame(FinancialEligibilityService::RESULT_NOT_ELIGIBLE, $ketat['result']);

        // Satu-satunya kunci untuk keputusan ini adalah kunci yang sudah ada.
        $this->patchJson('/api/v1/finance/settings', ['allow_re_registration_with_outstanding' => true])->assertOk();

        $longgar = $this->check($student->id, 're_registration');
        $this->assertSame('allow', $longgar['rule']);
        $this->assertSame(FinancialEligibilityService::RESULT_ELIGIBLE, $longgar['result']);
        // Aksi lain tetap memakai kebijakan globalnya sendiri.
        $this->assertSame('approve', $this->ruleOf($student->id, 'exam'));
    }

    // =================================================================
    // Batas wewenang (§11: Finance tidak mengubah status akademik)
    // =================================================================

    public function test_pemeriksaan_kelayakan_tidak_menyentuh_status_akademik(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('ELG030');
        $this->issuedInvoice($student, '2026-08', Carbon::create(2026, 8, 30)->toDateString());

        $sebelum = DB::table('students')->where('id', $student->id)->first();
        $historiSebelum = DB::table('student_histories')->where('student_id', $student->id)->count();

        // Semua aksi diperiksa dua kali, termasuk yang ditahan.
        $this->getJson('/api/v1/finance/eligibility/'.$student->id)->assertOk();
        $this->getJson('/api/v1/finance/eligibility/'.$student->id.'/graduation_clearance')->assertOk();
        $this->getJson('/api/v1/finance/eligibility/'.$student->id.'/exam')->assertOk();

        $sesudah = DB::table('students')->where('id', $student->id)->first();

        $this->assertSame((array) $sebelum, (array) $sesudah);
        $this->assertSame($historiSebelum, DB::table('student_histories')->where('student_id', $student->id)->count());
        $this->assertSame(0, DB::table('activity_log')->where('subject_type', Student::class)->count());
    }

    public function test_layar_kelayakan_hanya_baca_dan_ber_permission(): void
    {
        $student = $this->student('ELG031');

        // Tanpa login sama sekali: tidak boleh tahu siapa pun menunggak.
        $this->getJson('/api/v1/finance/eligibility/'.$student->id.'/exam')->assertUnauthorized();
        $this->getJson('/api/v1/finance/eligibility/rules')->assertUnauthorized();

        // Tagihan lewat jatuh tempo lebih dulu (butuh hak pencatatan), lalu
        // layar kelayakannya dibaca berganti-ganti peran.
        $this->actingAsRole('finance-admin');
        $this->issuedInvoice($student, '2026-09', Carbon::create(2026, 9, 26)->toDateString());

        $this->actingAsRole('guru');
        $this->getJson('/api/v1/finance/eligibility/'.$student->id.'/exam')->assertForbidden();
        $this->getJson('/api/v1/finance/eligibility/rules')->assertForbidden();

        // Petugas keuangan membacanya; menyetujui bukan haknya (§3).
        $officer = $this->actingAsRole('finance-officer');
        $this->getJson('/api/v1/finance/eligibility/'.$student->id.'/exam')->assertOk();
        $this->assertTrue($officer->can('finance.eligibility.view'));
        $this->assertFalse($officer->can('finance.eligibility.approve'));

        // Manajer membacanya DAN memegang hak persetujuan yang disebut putusan
        // REQUIRES_APPROVAL.
        $manager = $this->actingAsRole('finance-manager');
        $this->getJson('/api/v1/finance/eligibility/'.$student->id.'/exam')->assertOk();
        $this->assertTrue($manager->can('finance.eligibility.approve'));

        // Tidak ada jalur tulis: memaksa kelayakan "lolos" dari endpoint Finance
        // tidak mungkin, karena endpointnya memang tidak ada.
        $this->postJson('/api/v1/finance/eligibility/'.$student->id.'/exam', [])->assertMethodNotAllowed();

        // Aksi yang tidak dikenal §11 ditolak dengan 422, bukan 500.
        $this->getJson('/api/v1/finance/eligibility/'.$student->id.'/upacara-bendera')->assertStatus(422);

        // Santri yang bukan santri: 404 dari model binding, bukan 500.
        $this->getJson('/api/v1/finance/eligibility/999999/exam')->assertNotFound();

        // `as_of` dibaca mundur: hari H tagihan itu belum lewat tempo.
        $telat = $this->getJson('/api/v1/finance/eligibility/'.$student->id.'/graduation_clearance')
            ->assertOk()
            ->json('data');
        $this->assertSame(FinancialEligibilityService::RESULT_NOT_ELIGIBLE, $telat['result']);

        $mundur = $this->getJson('/api/v1/finance/eligibility/'.$student->id.'/graduation_clearance?as_of=2026-09-25')
            ->assertOk()
            ->json('data');
        $this->assertSame('2026-09-25', $mundur['as_of']);
        $this->assertSame(FinancialEligibilityService::RESULT_ELIGIBLE, $mundur['result']);

        // `as_of` yang rusak adalah salah ketik, bukan 500.
        $this->getJson('/api/v1/finance/eligibility/'.$student->id.'/exam?as_of=bukan-tanggal')->assertStatus(422);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function student(string $nis): Student
    {
        return Student::query()->firstOrCreate(
            ['nis' => $nis],
            ['full_name' => 'Santri '.$nis, 'is_active' => true],
        );
    }

    /** Billing tergenerate + invoice terbit, jatuh tempo sesuai argumen. */
    private function issuedInvoice(Student $student, string $period, string $due): FinanceInvoice
    {
        $package = $this->package('PKT-ELG-'.substr($period, 0, 7).'-'.$student->id);

        $billingId = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id,
            'period_key' => $period,
            'student_ids' => [$student->id],
            'due_date' => $due,
        ])->assertOk()->json('data.billings.0');

        return FinanceInvoice::findOrFail(
            (int) $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated()->json('data.id')
        );
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

    /** @return array<string, mixed> */
    private function check(int $studentId, string $action): array
    {
        return $this->getJson('/api/v1/finance/eligibility/'.$studentId.'/'.$action)
            ->assertOk()
            ->json('data');
    }

    private function ruleOf(int $studentId, string $action): string
    {
        return $this->check($studentId, $action)['rule'];
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
