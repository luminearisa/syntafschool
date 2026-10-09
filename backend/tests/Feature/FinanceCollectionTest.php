<?php

namespace Tests\Feature;

use App\Models\FinanceAdjustment;
use App\Models\FinanceCashAccount;
use App\Models\FinanceCollectionAction;
use App\Models\FinanceFeePackage;
use App\Models\FinanceFeeType;
use App\Models\FinanceInvoice;
use App\Models\FinancialTransaction;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\FinanceSettingsService;
use Database\Seeders\FinanceConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Collection / penagihan (§6) lewat HTTP, plus laporan penagihannya (§20).
 *
 * §6 memberi dua aturan yang jadi sumbu test ini:
 *
 * 1. "Simpan history setiap collection action" — tiap aksi jadi baris sejarah
 *    dengan stage yang dibekukan pada saat ia dicatat.
 * 2. "Jangan mengubah outstanding secara manual dari halaman collection" — sisa
 *    piutang tetap hasil invoice − alokasi pembayaran − write-off. Halaman
 *    penagihan hanya menambah sejarah, karena itu test-test di sini membandingkan
 *    angka piutang sebelum dan sesudah aksi: tidak boleh ada yang bergeser.
 *
 * Stage workflow (issued → due soon → due → overdue → reminder → escalation →
 * collection action → settled) dihitung dari jatuh tempo, sisa tagihan, dan aksi
 * yang sudah tercatat. Test menjaga ketiga sumber itu: tanggal menggerakkan
 * stage, aksi juga, dan umur tunggakan menaikkan tangga tanpa menunggu ada yang
 * mencatat apa pun.
 */
class FinanceCollectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceConfigSeeder::class);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function actingAsRole(string $role): User
    {
        $email = str_replace('-', '.', $role).'@schoolerp.test';

        $user = User::query()->firstWhere('email', $email)
            ?? User::factory()->create(['email' => $email, 'is_active' => true]);

        $user->syncRoles([$role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /** Santri + akun login-nya, supaya reminder punya penerima yang nyata. */
    private function student(string $nis): Student
    {
        $student = Student::query()->firstOrCreate(
            ['nis' => $nis],
            ['full_name' => 'Santri '.$nis, 'is_active' => true],
        );

        if (! User::query()->where('userable_type', Student::class)->where('userable_id', $student->id)->exists()) {
            User::factory()->create([
                'name' => 'Akun '.$nis,
                'email' => strtolower($nis).'@schoolerp.test',
                'is_active' => true,
                'userable_type' => Student::class,
                'userable_id' => $student->id,
            ]);
        }

        return $student;
    }

    /** Paket 850.000 = SPP 350.000 (akun 4100) + asrama 500.000 (akun 4200). */
    private function package(string $code): FinanceFeePackage
    {
        $package = FinanceFeePackage::query()->firstOrCreate(
            ['code' => $code],
            ['name' => 'Paket '.$code, 'boarding_type' => 'all', 'is_active' => true],
        );

        if ($package->items()->count() === 0) {
            $types = FinanceFeeType::query()->whereIn('code', ['SPP', 'UANG_ASRAMA'])->pluck('id', 'code');

            $package->items()->createMany([
                ['fee_type_id' => $types['SPP'], 'amount' => 350000, 'frequency' => 'monthly', 'is_active' => true],
                ['fee_type_id' => $types['UANG_ASRAMA'], 'amount' => 500000, 'frequency' => 'monthly', 'is_active' => true],
            ]);
        }

        return $package;
    }

    /**
     * Tagihan terbit dengan jatuh tempo tertentu. Penerbitan selalu dilakukan
     * sebagai admin supaya hak catat penagihan tidak ikut teruji di sini.
     */
    private function issue(string $nis, string $dueDate, ?string $packageCode = null): FinanceInvoice
    {
        $this->actingAsRole('finance-admin');
        $student = $this->student($nis);
        $package = $this->package($packageCode ?? 'PKT-CLN-'.$nis);

        $billingId = $this->postJson('/api/v1/finance/billings/generate', [
            'fee_package_id' => $package->id,
            'period_key' => Carbon::today()->format('Y-m'),
            'student_ids' => [$student->id],
            'due_date' => $dueDate,
        ])->assertOk()->json('data.billings.0');

        return FinanceInvoice::findOrFail(
            (int) $this->postJson("/api/v1/finance/billings/{$billingId}/invoice")->assertCreated()->json('data.id')
        );
    }

    /** Bayar sebagian/penuh lewat tunai yang tercatat (payment confirmed). */
    private function pay(FinanceInvoice $invoice, float $amount): void
    {
        $this->actingAsRole('finance-admin');

        $this->postJson('/api/v1/finance/payments', [
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'method' => 'cash',
            'cash_account_id' => FinanceCashAccount::query()->where('code', 'KAS-SEKOLAH')->value('id'),
        ])->assertCreated();
    }

    /** Baris antrean penagihan untuk satu invoice. */
    private function queueRow(int $invoiceId, array $query = []): array
    {
        $url = '/api/v1/finance/collections'.($query === [] ? '' : '?'.http_build_query($query));

        $rows = $this->getJson($url)->assertOk()->json('data.rows');
        $row = collect($rows)->firstWhere('invoice_id', $invoiceId);

        $this->assertIsArray($row, "Baris invoice {$invoiceId} tidak ada di antrean penagihan.");

        return $row;
    }

    /** Ajukan write-off sebagian atas sebuah invoice (dipakai uji tautan dokumen). */
    private function writeOffRequest(FinanceInvoice $invoice, float $amount): FinanceAdjustment
    {
        $this->actingAsRole('finance-officer');

        $id = (int) $this->postJson("/api/v1/finance/invoices/{$invoice->id}/write-off", [
            'amount' => $amount,
            'reason' => 'Wali tidak mampu membayar sisa tagihannya',
        ])->assertCreated()->json('data.id');

        return FinanceAdjustment::findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(FinanceInvoice $invoice): array
    {
        $invoice->refresh();

        return [
            'total' => (float) $invoice->total,
            'paid_amount' => (float) $invoice->paid_amount,
            'written_off_amount' => (float) $invoice->written_off_amount,
            'remaining_amount' => (float) $invoice->remaining_amount,
            'status' => $invoice->status,
        ];
    }

    // =================================================================
    // Stage dari tanggal jatuh tempo
    // =================================================================

    public function test_tagihan_lewat_jatuh_tempo_tampil_sebagai_tunggakan(): void
    {
        $invoice = $this->issue('CLN001', Carbon::today()->subDays(10)->toDateString());

        $this->actingAsRole('finance-officer');
        $row = $this->queueRow($invoice->id);

        $this->assertSame('overdue', $row['stage']);
        $this->assertSame('Tunggakan', $row['stage_label']);
        $this->assertSame(10, (int) $row['days_overdue']);
        $this->assertSame('1_30', $row['bucket']);
        $this->assertEquals(850000.0, (float) $row['remaining_amount']);
        $this->assertSame(0, (int) $row['action_count']);
        $this->assertNull($row['last_action_at']);
    }

    public function test_jatuh_tempo_hari_ini_berada_di_tangga_due(): void
    {
        $invoice = $this->issue('CLN002', Carbon::today()->toDateString());

        $this->actingAsRole('finance-officer');
        $this->assertSame('due', $this->queueRow($invoice->id)['stage']);
    }

    public function test_jendela_due_soon_ditentukan_konfigurasi(): void
    {
        // "Hampir jatuh tempo" adalah kebijakan, bukan angka yang dikeraskan:
        // jendela 7 hari diubah jadi 30 hari dan tagihan berumur sama langsung
        // berubah kelas.
        $invoice = $this->issue('CLN003', Carbon::today()->addDays(20)->toDateString());

        $this->actingAsRole('finance-officer');
        $this->assertSame('issued', $this->queueRow($invoice->id)['stage']);

        app(FinanceSettingsService::class)->update(['collection_due_soon_days' => 30]);

        $this->assertSame('due_soon', $this->queueRow($invoice->id)['stage']);
        $this->assertSame(30, (int) $this->getJson('/api/v1/finance/collections/dashboard')->assertOk()->json('data.settings.due_soon_days'));
    }

    public function test_umur_tunggakan_menaikkan_tangga_tanpa_perlu_aksi(): void
    {
        $invoice = $this->issue('CLN004', Carbon::today()->subDays(45)->toDateString());

        $this->actingAsRole('finance-officer');
        $row = $this->queueRow($invoice->id);

        // 45 hari > ambang eskalasi 30 hari → escalation walau belum ada aksi.
        $this->assertSame('escalation', $row['stage']);
        $this->assertSame('31_60', $row['bucket']);
        $this->assertSame(45, (int) $row['days_overdue']);
    }

    // =================================================================
    // Mencatat aksi
    // =================================================================

    public function test_reminder_mencatat_sejarah_dan_naik_tangga(): void
    {
        $invoice = $this->issue('CLN010', Carbon::today()->subDays(5)->toDateString());
        $officer = $this->actingAsRole('finance-officer');

        $this->postJson("/api/v1/finance/collections/{$invoice->id}/actions", [
            'action_type' => 'reminder',
            'notes' => 'Diingatkan lewat aplikasi',
        ])->assertCreated()
            ->assertJsonPath('data.action_type', 'reminder')
            ->assertJsonPath('data.action_label', 'Pengingat')
            ->assertJsonPath('data.stage', 'overdue')
            ->assertJsonPath('data.stage_label', 'Tunggakan')
            ->assertJsonPath('data.created_by', $officer->id);

        $this->assertSame('reminder', $this->queueRow($invoice->id)['stage']);

        $this->getJson("/api/v1/finance/collections/{$invoice->id}/history")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.notes', 'Diingatkan lewat aplikasi')
            ->assertJsonPath('data.0.action_label', 'Pengingat');
    }

    public function test_reminder_mengirim_notifikasi_dan_mencatat_yang_terkirim(): void
    {
        $invoice = $this->issue('CLN011', Carbon::today()->subDays(3)->toDateString());

        $this->actingAsRole('finance-officer');

        // Santri punya akun login → pengingat pertama benar-benar terkirim.
        $this->postJson("/api/v1/finance/collections/{$invoice->id}/actions", ['action_type' => 'reminder'])
            ->assertCreated()->assertJsonPath('data.notified_count', 1);

        // Reminder kedua di hari yang sama ditahan deduplikasi: aksinya tetap
        // tercatat, tapi wali tidak dibombardir.
        $this->postJson("/api/v1/finance/collections/{$invoice->id}/actions", ['action_type' => 'email'])
            ->assertCreated()->assertJsonPath('data.notified_count', 0);

        // WhatsApp bukan kanal yang dimiliki sistem: aksinya tersimpan sebagai
        // sejarah, dan notified_count-nya jujur 0 — bukan angka optimistis.
        $this->postJson("/api/v1/finance/collections/{$invoice->id}/actions", ['action_type' => 'whatsapp'])
            ->assertCreated()->assertJsonPath('data.notified_count', 0);

        $this->getJson("/api/v1/finance/collections/{$invoice->id}/history")->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_tangga_eskalasi_butuh_hak_khusus(): void
    {
        $invoice = $this->issue('CLN012', Carbon::today()->subDays(20)->toDateString());

        // Petugas: reminder boleh, telepon tidak (§6 memisahkan keduanya).
        $this->actingAsRole('finance-officer');
        $this->postJson("/api/v1/finance/collections/{$invoice->id}/actions", ['action_type' => 'reminder'])->assertCreated();
        $this->postJson("/api/v1/finance/collections/{$invoice->id}/actions", ['action_type' => 'phone_call'])->assertStatus(403);

        // Manajer memegang hak eskalasi.
        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/finance/collections/{$invoice->id}/actions", ['action_type' => 'phone_call'])
            ->assertCreated()
            ->assertJsonPath('data.stage', 'reminder');

        $this->assertSame('escalation', $this->queueRow($invoice->id)['stage']);
    }

    public function test_tidak_bisa_melihat_maupun_mencatat_penagihan_tanpa_izin(): void
    {
        $invoice = $this->issue('CLN013', Carbon::today()->subDays(5)->toDateString());

        // Kasir tidak memegang finance.collection.*; auditor hanya membaca.
        $this->actingAsRole('kasir');
        $this->getJson('/api/v1/finance/collections')->assertStatus(403);
        $this->getJson('/api/v1/finance/collections/dashboard')->assertStatus(403);
        $this->postJson("/api/v1/finance/collections/{$invoice->id}/actions", ['action_type' => 'reminder'])->assertStatus(403);

        $this->actingAsRole('auditor');
        $this->getJson('/api/v1/finance/collections')->assertOk();
        $this->postJson("/api/v1/finance/collections/{$invoice->id}/actions", ['action_type' => 'reminder'])->assertStatus(403);
    }

    public function test_aksi_bertaut_dokumen_wajib_menunjuk_penyesuaian_milik_invoice_itu(): void
    {
        $invoice = $this->issue('CLN014', Carbon::today()->subDays(40)->toDateString());
        $other = $this->issue('CLN015', Carbon::today()->subDays(40)->toDateString());

        // Dua pengajuan write-off dibuat lebih dulu supaya haknya tidak bertabrakan.
        $own = $this->writeOffRequest($invoice, 100000);
        $foreign = $this->writeOffRequest($other, 50000);

        $this->actingAsRole('finance-manager');
        $url = "/api/v1/finance/collections/{$invoice->id}/actions";

        // Write-off request tanpa dokumen = permintaan yang tidak bisa ditelusuri.
        $this->postJson($url, ['action_type' => FinanceCollectionAction::TYPE_WRITE_OFF_REQUEST])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reference_id');

        // Dokumen milik invoice lain ditolak.
        $this->postJson($url, [
            'action_type' => FinanceCollectionAction::TYPE_WRITE_OFF_REQUEST,
            'reference_id' => $foreign->id,
        ])->assertStatus(422)->assertJsonValidationErrors('reference_id');

        $this->postJson($url, [
            'action_type' => FinanceCollectionAction::TYPE_WRITE_OFF_REQUEST,
            'reference_id' => $own->id,
        ])->assertCreated()
            ->assertJsonPath('data.reference_id', $own->id)
            ->assertJsonPath('data.reference.number', $own->adjustment_number);

        // Jenis aksi di luar daftar §6 ditolak.
        $this->postJson($url, ['action_type' => 'surat_perang'])->assertStatus(422)->assertJsonValidationErrors('action_type');
    }

    // =================================================================
    // Larangan §6: collection tidak mengubah outstanding
    // =================================================================

    public function test_mencatat_aksi_tidak_mengubah_angka_piutang_sedikit_pun(): void
    {
        $invoice = $this->issue('CLN020', Carbon::today()->subDays(12)->toDateString());
        $before = $this->snapshot($invoice);

        $this->actingAsRole('finance-officer');
        $arTotal = (float) $this->getJson('/api/v1/finance/receivables/summary')->assertOk()->json('data.total_receivable');
        $journals = FinancialTransaction::query()->count();

        $this->postJson("/api/v1/finance/collections/{$invoice->id}/actions", [
            'action_type' => 'parent_notification',
            'notes' => 'Wali dihubungi',
        ])->assertCreated();

        $this->assertSame($before, $this->snapshot($invoice));
        $this->actingAsRole('finance-officer');
        $this->assertEquals(
            $arTotal,
            (float) $this->getJson('/api/v1/finance/receivables/summary')->assertOk()->json('data.total_receivable'),
        );
        // Penagihan bukan peristiwa pembukuan: tidak boleh ada jurnal baru.
        $this->assertSame($journals, FinancialTransaction::query()->count());
    }

    public function test_pembayaran_setelah_reminder_menutup_piutang_tanpa_menghapus_sejarah(): void
    {
        $invoice = $this->issue('CLN021', Carbon::today()->subDays(8)->toDateString());

        $this->actingAsRole('finance-officer');
        $this->postJson("/api/v1/finance/collections/{$invoice->id}/actions", ['action_type' => 'reminder'])->assertCreated();
        $this->assertSame('reminder', $this->queueRow($invoice->id)['stage']);

        $this->pay($invoice, 500000);

        $this->actingAsRole('finance-officer');
        $row = $this->queueRow($invoice->id);
        $this->assertEquals(350000.0, (float) $row['remaining_amount']);
        $this->assertSame('reminder', $row['stage']);
        $this->assertSame(1, (int) $row['action_count']);

        $this->pay($invoice, 350000);

        // Lunas → hilang dari antrean dan dari total AR, tapi sejarahnya tetap
        // terbaca: penagihan yang sudah dikerjakan tidak dihapus oleh pelunasan.
        $this->actingAsRole('finance-officer');
        $rows = $this->getJson('/api/v1/finance/collections')->assertOk()->json('data.rows');
        $this->assertNull(collect($rows)->firstWhere('invoice_id', $invoice->id));
        $this->assertEquals(0.0, (float) $this->getJson('/api/v1/finance/receivables/summary')->assertOk()->json('data.total_receivable'));
        $this->getJson("/api/v1/finance/collections/{$invoice->id}/history")->assertOk()->assertJsonCount(1, 'data');

        // Dan aksi tidak boleh dicatat lagi atas piutang yang sudah selesai.
        $this->postJson("/api/v1/finance/collections/{$invoice->id}/actions", ['action_type' => 'reminder'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice_id');
    }

    public function test_pembayaran_sebagian_tidak_dihitung_sebagai_penyelesaian(): void
    {
        $invoice = $this->issue('CLN022', Carbon::today()->subDays(4)->toDateString());
        $this->pay($invoice, 250000);

        $this->actingAsRole('finance-officer');
        $row = $this->queueRow($invoice->id);

        $this->assertEquals(600000.0, (float) $row['remaining_amount']);
        $this->assertSame('overdue', $row['stage']);
        $this->assertSame(FinanceInvoice::STATUS_PARTIAL, $row['status']);
    }

    // =================================================================
    // Dashboard penagihan §6
    // =================================================================

    public function test_dashboard_menjumlah_ar_per_ember_kelompok_dan_tangga_workflow(): void
    {
        $today = Carbon::today();
        // SPP 350k + asrama 500k tiap tagihan.
        $this->issue('CLN030', $today->copy()->addDays(10)->toDateString(), 'PKT-DASH-A');  // issued / current
        $this->issue('CLN031', $today->copy()->subDays(15)->toDateString(), 'PKT-DASH-B');  // overdue / 1-30
        $this->issue('CLN032', $today->copy()->subDays(75)->toDateString(), 'PKT-DASH-C');  // escalation / 61-90

        $this->actingAsRole('finance-officer');
        $data = $this->getJson('/api/v1/finance/collections/dashboard')->assertOk()->json('data');

        $this->assertSame(3, (int) $data['receivable']['invoice_count']);
        $this->assertEquals(2550000.0, (float) $data['receivable']['total']);
        $this->assertEquals(850000.0, (float) $data['receivable']['current']);
        $this->assertEquals(1700000.0, (float) $data['receivable']['overdue']);

        // Ember umur harus lengkap termasuk yang kosong, supaya dashboard tidak
        // tampak lebih sehat hanya karena barisnya hilang.
        foreach (['current', '1_30', '31_60', '61_90', '90_plus'] as $bucket) {
            $this->assertArrayHasKey($bucket, $data['aging']);
        }
        $this->assertEquals(850000.0, (float) $data['aging']['current']['total']);
        $this->assertEquals(850000.0, (float) $data['aging']['1_30']['total']);
        $this->assertEquals(0.0, (float) $data['aging']['31_60']['total']);
        $this->assertEquals(850000.0, (float) $data['aging']['61_90']['total']);
        $this->assertEquals(0.0, (float) $data['aging']['90_plus']['total']);

        // Pembagian kelompok AR dibaca dari akun pendapatan tiap item, bukan dari
        // nama jenis tagihan.
        $byGroup = collect($data['by_category'])->keyBy('group');
        $this->assertEquals(1050000.0, (float) $byGroup['spp']['outstanding']);
        $this->assertEquals(1500000.0, (float) $byGroup['boarding']['outstanding']);
        $this->assertEquals(0.0, (float) $byGroup['re_registration']['outstanding']);
        $this->assertEquals(0.0, (float) $byGroup['other']['outstanding']);
        $this->assertEquals(
            (float) $data['receivable']['total'],
            round(array_sum(array_column($data['by_category'], 'outstanding')), 2),
            'Kelompok AR harus menjumlah persis sama dengan total AR.'
        );

        // Tangga workflow lengkap: empat invoice tersebar di issued/overdue/escalation.
        $workflow = collect($data['workflow'])->keyBy('stage');
        $this->assertCount(8, $data['workflow']);
        $this->assertSame(1, (int) $workflow->get('issued')['count']);
        $this->assertSame(0, (int) $workflow->get('due_soon')['count']);
        $this->assertSame(1, (int) $workflow->get('overdue')['count']);
        $this->assertSame(1, (int) $workflow->get('escalation')['count']);
        $this->assertEquals(850000.0, (float) $workflow->get('escalation')['total']);
        $this->assertSame(0, (int) $workflow->get('settled')['count']);

        // Tunggakan yang belum pernah dihubungi = titik mulai penagihan.
        $this->assertSame(2, (int) $data['attention']['never_contacted_overdue']);
        $this->assertSame(0, (int) $data['attention']['contacted_invoices']);
    }

    public function test_dashboard_top_penunggak_dan_kpi_ya_bisa_dibuka_jadi_detail(): void
    {
        $today = Carbon::today();
        $heavy = $this->issue('CLN040', $today->copy()->subDays(20)->toDateString(), 'PKT-TOP-A');
        $this->issue('CLN040', $today->copy()->subDays(50)->toDateString(), 'PKT-TOP-B');
        $this->issue('CLN041', $today->copy()->subDays(10)->toDateString(), 'PKT-TOP-C');

        $this->actingAsRole('finance-officer');
        $data = $this->getJson('/api/v1/finance/collections/dashboard')->assertOk()->json('data');

        $top = collect($data['top_overdue']);
        $this->assertSame('Santri CLN040', $top->first()['customer']);
        $this->assertEquals(1700000.0, (float) $top->first()['overdue']);
        $this->assertSame(2, (int) $top->first()['invoices']);
        $this->assertSame($today->copy()->subDays(50)->toDateString(), $top->first()['oldest_due_date']);
        $this->assertSame('Santri CLN041', $top->last()['customer']);

        // KPI harus membuka datanya: filter santri yang sama menghasilkan baris
        // yang sama dengan angka dashboard.
        $filtered = $this->getJson('/api/v1/finance/collections?student_id='.$heavy->student_id)->assertOk()->json('data.rows');
        $this->assertCount(2, $filtered);
        $this->assertEquals(1700000.0, round((float) collect($filtered)->sum('remaining_amount'), 2));

        $this->postJson("/api/v1/finance/collections/{$heavy->id}/actions", ['action_type' => 'reminder'])
            ->assertCreated()->assertJsonPath('data.notified_count', 1);

        // Saringan stage bekerja di atas stage yang dihitung, bukan kolom status.
        $stages = collect($this->getJson('/api/v1/finance/collections?stage=reminder')->assertOk()->json('data.rows'))->pluck('invoice_id');
        $this->assertSame([$heavy->id], $stages->all());

        $never = collect($this->getJson('/api/v1/finance/collections?never_contacted=1')->assertOk()->json('data.rows'))->pluck('invoice_id');
        $this->assertNotContains($heavy->id, $never->all());
        $this->assertCount(2, $never);
    }

    public function test_dashboard_performance_dihitung_dari_alokasi_pembayaran(): void
    {
        $invoice = $this->issue('CLN050', Carbon::today()->subDays(6)->toDateString(), 'PKT-PERF');
        $this->pay($invoice, 500000);

        $this->actingAsRole('finance-officer');
        $perf = $this->getJson('/api/v1/finance/collections/dashboard')->assertOk()->json('data.performance');

        // Ditagih 850k, tertagih 500k (dari alokasi payment confirmed), sisa 350k
        // dan seluruh sisanya sudah lewat jatuh tempo.
        $this->assertEquals(850000.0, (float) $perf['billed']);
        $this->assertEquals(500000.0, (float) $perf['collected']);
        $this->assertEquals(0.0, (float) $perf['written_off']);
        $this->assertEquals(350000.0, (float) $perf['outstanding']);
        $this->assertEquals(350000.0, (float) $perf['overdue']);
        $this->assertEquals(58.82, (float) $perf['collection_rate']);
        $this->assertEquals(100.0, (float) $perf['overdue_rate']);
    }

    // =================================================================
    // Laporan penagihan §20 + ekspor §21
    // =================================================================

    public function test_laporan_penagihan_memuat_rate_dan_baris_total(): void
    {
        $invoice = $this->issue('CLN060', Carbon::today()->subDays(9)->toDateString(), 'PKT-RPT');
        $this->pay($invoice, 350000);

        $this->actingAsRole('finance-officer');
        $this->postJson("/api/v1/finance/collections/{$invoice->id}/actions", ['action_type' => 'reminder'])->assertCreated();

        $report = $this->getJson('/api/v1/finance/reports/collection')->assertOk()->json('data');
        $rows = collect($report['rows']);

        $line = $rows->firstWhere('invoice_id', $invoice->id);
        $this->assertSame('reminder', $line['stage']);
        $this->assertEquals(500000.0, (float) $line['remaining_amount']);
        $this->assertSame('Pengingat', $line['action_label']);

        $total = $rows->last();
        $this->assertSame('TOTAL PENAGIHAN', $total['customer']);
        $this->assertEquals(850000.0, (float) $total['total']);
        $this->assertEquals(350000.0, (float) $total['paid_amount']);
        $this->assertEquals(500000.0, (float) $total['remaining_amount']);
        $this->assertStringContainsString('Collection rate 41.18%', $total['stage_label']);
        $this->assertStringContainsString('Overdue rate 100.00%', $total['stage_label']);

        // Satu baris data + satu baris TOTAL, dan jumlah barisnya sama dengan
        // yang dilihat layar.
        $this->assertCount(2, $rows);
        $this->assertSame(2, (int) $report['total']);
        $this->assertArrayHasKey('action_label', $report['headers']);
    }

    public function test_ekspor_penagihan_hanya_membawa_invoice_outstanding(): void
    {
        $today = Carbon::today();
        $open = $this->issue('CLN070', $today->copy()->subDays(4)->toDateString(), 'PKT-EXP');
        $paid = $this->issue('CLN071', $today->copy()->subDays(4)->toDateString(), 'PKT-EXP2');
        $this->pay($paid, 850000);

        $this->actingAsRole('finance-manager'); // report.view + report.export
        $response = $this->get('/api/v1/finance/reports/collection/export')->assertOk();
        $body = $response->getContent();

        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'CSV harus diawali BOM UTF-8');
        $this->assertStringContainsString('No. Invoice', $body);
        $this->assertStringContainsString('Customer;NIS', $body);
        $this->assertStringContainsString('Tahap Penagihan', $body);
        $this->assertStringContainsString($open->invoice_number, $body);
        $this->assertStringNotContainsString($paid->invoice_number, $body);
        $this->assertStringContainsString('TOTAL PENAGIHAN', $body);
    }
}
