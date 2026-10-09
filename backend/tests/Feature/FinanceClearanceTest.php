<?php

namespace Tests\Feature;

use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceClearance;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Keberesan finansial / financial clearance (§12 Phase 5).
 *
 * Yang diuji di sini adalah tuntutan langsung §12, bukan CRUD-nya:
 *
 *  1. alur pemeriksaan berjalan apa adanya — penagihan, piutang, pembayaran
 *     yang belum terverifikasi, refund, deposit — dan rinciannya disebut
 *     lengkap (invoice, nominal, jatuh tempo, sisa, status), bukan "Belum Lunas";
 *  2. lima status bisa dibedakan, dan `blocked` vs `pending` vs `cleared`
 *     ditentukan konfigurasi (§11) + dua kunci §12, bukan oleh kode;
 *  3. keputusan (APPROVED/WAIVED) menuntut alasan, membekukan pemeriksaannya,
 *     DAN tidak menggerakkan satu rupiah pun: piutang tetap ada setelah
 *     dikecualikan, dan jumlah jurnal tidak berubah sama sekali.
 */
class FinanceClearanceTest extends TestCase
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
    // Alur pemeriksaan (§12)
    // =================================================================

    public function test_daftar_periksa_lima_langkah_dan_rincian_yang_menahan(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('CLR001');
        $invoice = $this->issuedInvoice($student, '2026-08', Carbon::create(2026, 8, 30)->toDateString());

        $clearance = $this->postJson('/api/v1/finance/clearances', [
            'student_id' => $student->id,
            'purpose' => FinanceClearance::PURPOSE_GRADUATION,
            'notes' => 'Persiapan kelulusan.',
        ])->assertCreated()->json('data');

        $this->assertSame('CLR-2026-00001', $clearance['clearance_number']);
        $this->assertSame(FinanceClearance::STATUS_BLOCKED, $clearance['status']);
        $this->assertSame('Ditahan', $clearance['status_label']);
        $this->assertSame(850000.0, (float) $clearance['outstanding_total']);
        $this->assertGreaterThan(0, $clearance['blocking_count']);
        $this->assertSame('2026-09-27', $clearance['evaluated_on']);
        $this->assertStringContainsString('850.000', $clearance['headline']);

        // §12: lima langkah pemeriksaan, urut, dan masing-masing memberi jawabannya
        // sendiri — termasuk yang sengaja tidak menahan.
        $this->assertSame(
            ['billing', 'receivable', 'pending_payment', 'refund', 'credit'],
            array_column($clearance['checklist'], 'key'),
        );

        $items = collect($clearance['checklist'])->keyBy('key');

        $this->assertSame(1, $items['billing']['count']);
        $this->assertSame(850000.0, (float) $items['billing']['amount']);
        $this->assertSame('ok', $items['billing']['result']);

        // "Show Outstanding": baris piutang menyebut invoice, nominal, jatuh tempo,
        // sisa, dan statusnya.
        $this->assertSame('blocking', $items['receivable']['result']);
        $line = $items['receivable']['lines'][0];
        $this->assertSame($invoice->invoice_number, $line['invoice_number']);
        $this->assertSame('2026-08-30', $line['due_date']);
        $this->assertSame(850000.0, (float) $line['remaining_amount']);
        $this->assertSame(FinanceInvoice::STATUS_ISSUED, $line['status']);
        $this->assertNotEmpty($line['description']);

        $this->assertSame('ok', $items['pending_payment']['result']);
        $this->assertSame('ok', $items['refund']['result']);
        $this->assertSame('ok', $items['credit']['result']);

        // Keperluan lain pada santri yang sama = dokumen lain, karena yang
        // ditanyakan berbeda dan keputusannya bisa berbeda pula.
        $ijazah = $this->postJson('/api/v1/finance/clearances', [
            'student_id' => $student->id,
            'purpose' => FinanceClearance::PURPOSE_CERTIFICATE,
        ])->assertCreated()->json('data');

        $this->assertNotSame($clearance['id'], $ijazah['id']);
        $this->assertSame('certificate', $ijazah['purpose']);
        // Aturan `certificate` bawaannya approve: angka yang sama, tapi jawabannya
        // "menunggu keputusan", bukan "ditahan".
        $this->assertSame(FinanceClearance::STATUS_PENDING, $ijazah['status']);
        $this->assertSame(850000.0, (float) $ijazah['outstanding_total']);

        $list = $this->getJson('/api/v1/finance/clearances?student_id='.$student->id)->assertOk();
        $this->assertCount(2, $list->json('data'));
        $this->assertSame(2, $list->json('meta.total'));
        $this->assertArrayHasKey('configuration', $list->json('meta'));

        // Filter status dipakai daftar tunggu: yang menunggu keputusan saja.
        $this->assertCount(
            1,
            $this->getJson('/api/v1/finance/clearances?status=pending')->assertOk()->json('data'),
        );
    }

    public function test_santri_bersih_langsung_bebas_dan_pengajuan_ulang_tidak_membuat_dokumen_kedua(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('CLR002');

        $first = $this->postJson('/api/v1/finance/clearances', [
            'student_id' => $student->id,
            'purpose' => FinanceClearance::PURPOSE_GRADUATION,
        ])->assertCreated()->json('data');

        $this->assertSame(FinanceClearance::STATUS_CLEARED, $first['status']);
        $this->assertSame(0, $first['blocking_count']);
        $this->assertSame(0.0, (float) $first['outstanding_total']);
        $this->assertStringContainsString('tidak tertahan', $first['headline']);

        // Tombol "ajukan" ditekan dua kali: dokumen yang sama diisi ulang.
        $second = $this->postJson('/api/v1/finance/clearances', [
            'student_id' => $student->id,
            'purpose' => FinanceClearance::PURPOSE_GRADUATION,
        ])->assertCreated()->json('data');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, FinanceClearance::query()->count());

        // Setelah piutangnya benar-benar ada, keberesan yang sama bergerak tanpa
        // perlu diajukan ulang: `refresh` menjawab dengan angka hari itu.
        $this->issuedInvoice($student, '2026-08', Carbon::create(2026, 8, 30)->toDateString());

        $jurnalSebelum = DB::table('finance_transactions')->count();

        $refreshed = $this->postJson('/api/v1/finance/clearances/'.$first['id'].'/refresh')
            ->assertOk()
            ->json('data');

        $this->assertSame($first['id'], $refreshed['id']);
        $this->assertSame(FinanceClearance::STATUS_BLOCKED, $refreshed['status']);
        $this->assertSame(850000.0, (float) $refreshed['outstanding_total']);
        $this->assertSame(1, FinanceClearance::query()->count());

        // Keberesan hanya menulis tabelnya sendiri: tidak ada jurnal baru, tidak
        // ada satu pun perubahan di baris santri (§11 berlaku penuh di §12).
        $this->assertSame($jurnalSebelum, DB::table('finance_transactions')->count());
        $this->assertSame(0, DB::table('activity_log')->where('subject_type', Student::class)->count());
    }

    // =================================================================
    // Keputusan (§12: APPROVED / WAIVED)
    // =================================================================

    public function test_persetujuan_menuntut_alasan_membekukan_angka_dan_tanpa_jurnal(): void
    {
        $admin = $this->actingAsRole('finance-admin');
        $student = $this->student('CLR003');
        $this->issuedInvoice($student, '2026-08', Carbon::create(2026, 8, 30)->toDateString());

        $clearance = $this->postJson('/api/v1/finance/clearances', [
            'student_id' => $student->id,
            'purpose' => FinanceClearance::PURPOSE_GRADUATION,
        ])->assertCreated()->json('data');

        $jurnalSebelum = DB::table('finance_transactions')->count();
        $barisJurnalSebelum = DB::table('finance_transaction_lines')->count();

        // Alasan bukan hiasan: tanpanya keputusan tidak bisa ditempelkan.
        $this->postJson('/api/v1/finance/clearances/'.$clearance['id'].'/approve', [])->assertStatus(422);
        $this->postJson('/api/v1/finance/clearances/'.$clearance['id'].'/approve', ['reason' => 'ya'])->assertStatus(422);

        $approved = $this->postJson('/api/v1/finance/clearances/'.$clearance['id'].'/approve', [
            'reason' => 'Tunggakan berjalan, orang tua sudah membuat janji bayar.',
        ])->assertOk()->json('data');

        $this->assertSame(FinanceClearance::STATUS_APPROVED, $approved['status']);
        $this->assertSame('Disetujui', $approved['status_label']);
        $this->assertStringStartsWith('2026-09-27', $approved['decided_at']);
        $this->assertSame((int) $admin->id, (int) $approved['decided_by']['id']);
        $this->assertSame($admin->name, $approved['decided_by']['name']);
        $this->assertStringContainsString('janji bayar', $approved['decision_reason']);
        $this->assertFalse($approved['is_open']);
        $this->assertTrue($approved['is_settled']);

        // Angka pada hari keputusanlah yang tercatat, bukan angka saat diajukan.
        $this->assertSame(850000.0, (float) $approved['outstanding_total']);
        $this->assertStringContainsString('850.000', $approved['headline']);

        // Keputusan final tidak dibuka kembali dan tidak diperiksa ulang.
        $this->postJson('/api/v1/finance/clearances/'.$clearance['id'].'/approve', ['reason' => 'sekali lagi'])->assertStatus(422);
        $this->postJson('/api/v1/finance/clearances/'.$clearance['id'].'/refresh')->assertStatus(422);
        $this->postJson('/api/v1/finance/clearances/'.$clearance['id'].'/waive', [
            'reason' => 'sudah diputuskan orang lain',
        ])->assertStatus(422);

        // ... dan dokumen baru untuk keperluan yang sama tetap boleh diajukan:
        // yang beku adalah keputusan itu, bukan santri selamanya.
        $baru = $this->postJson('/api/v1/finance/clearances', [
            'student_id' => $student->id,
            'purpose' => FinanceClearance::PURPOSE_GRADUATION,
        ])->assertCreated()->json('data');
        $this->assertNotSame($clearance['id'], $baru['id']);
        $this->assertSame(FinanceClearance::STATUS_BLOCKED, $baru['status']);
        $this->assertSame(2, FinanceClearance::query()->where('student_id', $student->id)->count());

        // Tidak satu rupiah pun bergerak: keputusan keberesan bukan jurnal.
        $this->assertSame($jurnalSebelum, DB::table('finance_transactions')->count());
        $this->assertSame($barisJurnalSebelum, DB::table('finance_transaction_lines')->count());
        $this->assertSame(0.0, (float) FinanceInvoice::query()->sum('written_off_amount'));

        // Jejak audit: dokumen keberesan tercatat dibuat dan diubah.
        $events = DB::table('activity_log')
            ->where('subject_type', FinanceClearance::class)
            ->pluck('event')
            ->sort()
            ->values()
            ->all();
        $this->assertContains('FINANCE_CLEARANCE_CREATED', $events);
        $this->assertContains('FINANCE_CLEARANCE_UPDATED', $events);
    }

    public function test_pengecualian_mengalahkan_aturan_tetapi_tidak_menghapus_piutang(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('CLR004');
        $invoice = $this->issuedInvoice($student, '2026-08', Carbon::create(2026, 8, 30)->toDateString());

        $akademikSebelum = (array) DB::table('students')->where('id', $student->id)->first();

        $clearance = $this->postJson('/api/v1/finance/clearances', [
            'student_id' => $student->id,
            'purpose' => FinanceClearance::PURPOSE_GRADUATION,
        ])->assertCreated()->json('data');
        $this->assertSame(FinanceClearance::STATUS_BLOCKED, $clearance['status']);

        $waived = $this->postJson('/api/v1/finance/clearances/'.$clearance['id'].'/waive', [
            'reason' => 'Anak dipindahtugaskan orang tuanya ke luar pulau.',
        ])->assertOk()->json('data');

        $this->assertSame(FinanceClearance::STATUS_WAIVED, $waived['status']);
        $this->assertSame('Dikecualikan', $waived['status_label']);

        // WAIVED != write-off: tagihannya tetap ada sebesar semula.
        $fresh = $invoice->fresh();
        $this->assertSame(850000.0, (float) $fresh->total);
        $this->assertSame(0.0, (float) $fresh->paid_amount);
        $this->assertSame(0.0, (float) $fresh->written_off_amount);
        $this->assertSame(FinanceInvoice::STATUS_ISSUED, $fresh->status);

        // Modul pemanggil membaca jawaban yang sama: beres, dengan alasannya.
        $verdict = $this->getJson('/api/v1/finance/clearances/check/'.$student->id.'/graduation')
            ->assertOk()
            ->json('data');

        $this->assertTrue($verdict['settled']);
        $this->assertSame(FinanceClearance::STATUS_WAIVED, $verdict['status']);
        $this->assertSame($clearance['id'], $verdict['clearance_id']);
        $this->assertStringContainsString('luar pulau', $verdict['decision_reason']);

        // Tagihan baru sesudah keputusan tidak membuka kembali pengecualian —
        // yang berubah cukup dicatat sebagai pengecualian baru.
        $this->issuedInvoice($student, '2026-09', Carbon::create(2026, 9, 20)->toDateString());

        $setelah = $this->getJson('/api/v1/finance/clearances/check/'.$student->id.'/graduation')
            ->assertOk()
            ->json('data');

        $this->assertTrue($setelah['settled']);
        $this->assertSame(FinanceClearance::STATUS_WAIVED, $setelah['status']);
        $this->assertSame(1700000.0, (float) $setelah['outstanding_total']);
        // Pemeriksaan hidupnya tetap jujur: ada yang menahan, hanya keputusannya
        // sudah diambil lebih dulu.
        $receivable = collect($setelah['checklist'])->firstWhere('key', 'receivable');
        $this->assertSame('blocking', $receivable['result']);
        $this->assertCount(2, $receivable['lines']);

        // Keberesan tidak menyentuh status akademik (§11 berlaku penuh di §12).
        $this->assertSame($akademikSebelum, (array) DB::table('students')->where('id', $student->id)->first());
    }

    // =================================================================
    // Dua kunci §12 + deposit §19
    // =================================================================

    public function test_pembayaran_yang_belum_terverifikasi_ditahan_atau_dilaporkan_sesuai_settings(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('CLR005');
        $invoice = $this->issuedInvoice($student, '2026-09', Carbon::create(2026, 10, 30)->toDateString());

        // Transfer bank yang belum diverifikasi: uangnya belum masuk kas.
        $payment = $this->pay($invoice, 850000, 'manual_transfer');
        $this->assertSame('pending', $payment['status']);

        $clearance = $this->postJson('/api/v1/finance/clearances', [
            'student_id' => $student->id,
            'purpose' => FinanceClearance::PURPOSE_GRADUATION,
        ])->assertCreated()->json('data');

        // Piutangnya belum jatuh tempo, jadi satu-satunya penahan adalah
        // pembayaran yang masih menunggu verifikasi.
        $items = collect($clearance['checklist'])->keyBy('key');
        $this->assertSame('ok', $items['receivable']['result']);
        $this->assertSame('blocking', $items['pending_payment']['result']);
        $this->assertSame($payment['payment_number'], $items['pending_payment']['lines'][0]['reference']);
        $this->assertSame(FinanceClearance::STATUS_BLOCKED, $clearance['status']);
        $this->assertStringContainsString('Verifikasi pembayaran', $items['pending_payment']['detail']);

        // Sekolah yang longgar cukup LAPOR: kunci Settings, bukan kode.
        $this->patchJson('/api/v1/finance/settings', [
            'clearance_block_on_pending_payment' => false,
        ])->assertOk();

        $mutakhir = $this->postJson('/api/v1/finance/clearances/'.$clearance['id'].'/refresh')
            ->assertOk()
            ->json('data');

        $items = collect($mutakhir['checklist'])->keyBy('key');
        $this->assertSame('attention', $items['pending_payment']['result']);
        $this->assertSame(0, $items['pending_payment']['blocking_count']);
        $this->assertSame(FinanceClearance::STATUS_CLEARED, $mutakhir['status']);
        $this->assertSame(0, $mutakhir['blocking_count']);

        // Konfigurasinya ikut dibaca layar.
        $configuration = $this->getJson('/api/v1/finance/clearances/config')->assertOk()->json('data');
        $this->assertFalse($configuration['block_on_pending_payment']);
        $this->assertTrue($configuration['block_on_open_refund']);
        $this->assertSame('graduation_clearance', $configuration['action_for_purpose']['graduation']);
    }

    public function test_refund_menahan_dan_deposit_hanya_dilaporkan(): void
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student('CLR006');
        $invoice = $this->issuedInvoice($student, '2026-09', Carbon::create(2026, 10, 30)->toDateString());

        // Lunas, lalu ada kelebihan bayar yang tertahan sebagai deposit (§19),
        // dan satu pengembalian yang belum selesai.
        $lunas = $this->pay($invoice, 850000);
        $this->assertSame('paid', $lunas['status']);

        $this->patchJson('/api/v1/finance/settings', ['overpayment_policy' => 'unallocated'])->assertOk();
        $deposit = $this->pay($invoice, 1000000);
        $this->assertSame('paid', $deposit['status']);

        $this->postJson('/api/v1/finance/payments/'.$lunas['id'].'/refunds', [
            'amount' => 300000,
            'reason' => 'Kesalahan nominal transfer orang tua.',
        ])->assertCreated();

        $clearance = $this->postJson('/api/v1/finance/clearances', [
            'student_id' => $student->id,
            'purpose' => FinanceClearance::PURPOSE_FINAL_SETTLEMENT,
        ])->assertCreated()->json('data');

        $items = collect($clearance['checklist'])->keyBy('key');

        // Santri yang pergi: hak yang belum dikembalikan menahan berkasnya.
        $this->assertSame('blocking', $items['refund']['result']);
        $this->assertSame(300000.0, (float) $items['refund']['amount']);
        // Deposit tetap hanya dilaporkan — itu uang santri, bukan alasan penolakan.
        $this->assertSame('attention', $items['credit']['result']);
        $this->assertSame(0, $items['credit']['blocking_count']);
        $this->assertSame(1000000.0, (float) $clearance['credit_total']);
        $this->assertSame(0.0, (float) $clearance['outstanding_total']);
        $this->assertSame(FinanceClearance::STATUS_BLOCKED, $clearance['status']);
        $this->assertSame(1, $clearance['blocking_count']);

        $this->patchJson('/api/v1/finance/settings', ['clearance_block_on_open_refund' => false])->assertOk();

        $mutakhir = $this->postJson('/api/v1/finance/clearances/'.$clearance['id'].'/refresh')
            ->assertOk()
            ->json('data');

        // Bersih secara aturan, dan deposit masih tertulis: §12 menuntut ia
        // diselesaikan, bukan ia dijadikan penahan.
        $this->assertSame(FinanceClearance::STATUS_CLEARED, $mutakhir['status']);
        $this->assertSame(1000000.0, (float) $mutakhir['credit_total']);
        $this->assertSame('attention', collect($mutakhir['checklist'])->keyBy('key')['credit']['result']);
    }

    // =================================================================
    // Wewenang & bentuk jawaban (§12 + §24)
    // =================================================================

    public function test_keberesan_dibaca_diajukan_dan_diputus_oleh_peran_yang_berbeda(): void
    {
        $student = $this->student('CLR007');

        // Tanpa login: daftar keberesan berisi tunggakan orang lain.
        $this->getJson('/api/v1/finance/clearances')->assertUnauthorized();
        $this->getJson('/api/v1/finance/clearances/check/'.$student->id.'/graduation')->assertUnauthorized();
        $this->getJson('/api/v1/finance/clearances/config')->assertUnauthorized();

        $this->actingAsRole('finance-admin');
        $this->issuedInvoice($student, '2026-08', Carbon::create(2026, 8, 30)->toDateString());
        $clearanceId = (int) $this->postJson('/api/v1/finance/clearances', [
            'student_id' => $student->id,
            'purpose' => FinanceClearance::PURPOSE_GRADUATION,
        ])->assertCreated()->json('data.id');

        // Guru boleh melihat nilai, tidak boleh melihat daftar keuangan sekolah.
        $this->actingAsRole('guru');
        $this->getJson('/api/v1/finance/clearances')->assertForbidden();
        $this->getJson('/api/v1/finance/clearances/'.$clearanceId)->assertForbidden();

        // Auditor: baca daftar periksa untuk memeriksa alasannya, tanpa hak tulis.
        $auditor = $this->actingAsRole('auditor');
        $this->getJson('/api/v1/finance/clearances/'.$clearanceId)->assertOk();
        $this->assertTrue($auditor->can('finance.clearance.view'));
        $this->assertFalse($auditor->can('finance.clearance.request'));
        $this->postJson('/api/v1/finance/clearances', [
            'student_id' => $student->id,
            'purpose' => FinanceClearance::PURPOSE_GRADUATION,
        ])->assertForbidden();

        // Petugas: mengajukan dan memutakhirkan boleh, memutuskan tidak (§3).
        $officer = $this->actingAsRole('finance-officer');
        $this->assertTrue($officer->can('finance.clearance.request'));
        $this->assertFalse($officer->can('finance.clearance.waive'));
        $this->postJson('/api/v1/finance/clearances/'.$clearanceId.'/refresh')->assertOk();
        $this->postJson('/api/v1/finance/clearances/'.$clearanceId.'/waive', [
            'reason' => 'Alasan yang sah tetapi bukan haknya.',
        ])->assertForbidden();

        // Kepala sekolah: pemegang keputusan (ia pemegang `academic:approve`).
        $kepala = $this->actingAsRole('kepala-sekolah');
        $this->assertTrue($kepala->can('finance.clearance.waive'));
        $this->assertFalse($kepala->can('finance.clearance.request'));
        $this->postJson('/api/v1/finance/clearances/'.$clearanceId.'/waive', [
            'reason' => 'Kelulusan sudah diputus rapat dewan guru.',
        ])->assertOk()->assertJsonPath('data.status', FinanceClearance::STATUS_WAIVED);

        // Input yang salah dijawab 422, barang yang tidak ada dijawab 404 —
        // diperiksa sebagai petugas, karena kepala sekolah tidak memegang hak
        // mengajukan dan akan dijawab 403 lebih dulu.
        $this->actingAsRole('finance-officer');

        $this->postJson('/api/v1/finance/clearances', ['student_id' => $student->id, 'purpose' => 'wisuda'])
            ->assertStatus(422);
        $this->postJson('/api/v1/finance/clearances', ['student_id' => 999999, 'purpose' => 'graduation'])
            ->assertStatus(422);
        $this->postJson('/api/v1/finance/clearances', ['purpose' => 'graduation'])->assertStatus(422);
        $this->getJson('/api/v1/finance/clearances/999999')->assertNotFound();
        $this->getJson('/api/v1/finance/clearances/check/'.$student->id.'/wisuda')->assertStatus(422);
        $this->getJson('/api/v1/finance/clearances/check/'.$student->id.'/graduation?as_of=bukan-tanggal')
            ->assertStatus(422);

        // Tidak ada endpoint untuk menulis status/checklist dari luar.
        $this->patchJson('/api/v1/finance/clearances/'.$clearanceId, ['status' => 'cleared'])
            ->assertMethodNotAllowed();
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
        $package = $this->package('PKT-CLR-'.substr($period, 0, 7).'-'.$student->id);

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

    /**
     * Catat pembayaran atas satu invoice. Tunai langsung `paid`; transfer bank
     * lahir `pending` (§ "pembayaran tertunda belum menyentuh kas").
     *
     * @return array<string, mixed>
     */
    private function pay(FinanceInvoice $invoice, float $amount, string $method = 'cash'): array
    {
        $body = [
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'method' => $method,
        ];

        $body[$method === 'cash' ? 'cash_account_id' : 'bank_account_id'] = $method === 'cash'
            ? FinanceCashAccount::query()->where('code', 'KAS-SEKOLAH')->value('id')
            : FinanceBankAccount::query()->where('code', 'BANK-OPS')->value('id');

        return $this->postJson('/api/v1/finance/payments', $body)
            ->assertCreated()
            ->json('data');
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
