<?php

namespace Tests\Feature\Ppdb;

use App\Models\Gender;
use App\Models\PaymentMethod;
use App\Models\PpdbApplicant;
use App\Models\PpdbApplicantDocument;
use App\Models\PpdbDocumentRequirement;
use App\Models\PpdbInvoice;
use App\Models\PpdbParentAccount;
use App\Models\PpdbPayment;
use App\Models\PpdbPeriod;
use App\Models\PpdbProgram;
use App\Models\PpdbRegistration;
use App\Models\PpdbSelectionParticipant;
use App\Models\PpdbSelectionStage;
use App\Models\PpdbWave;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Iris A4 — dashboard admin, empat grup laporan, ekspor CSV, dan cetak PDF PPDB.
 *
 * Data uji dibuat sendiri lewat model (bukan seeder modul lain) supaya test ini
 * tidak bergantung pada iris A1–A3. Bentuk yang dijaga:
 * - dashboard: statistik (12 kunci status), corong + persentase konversi,
 *   keuangan (tagihan/terkumpul/tunggakan + rincian kanal), distribusi
 *   (kuota & persen terisi), empat aksi tertunda, tren, dan cakupan periode;
 * - laporan: `type/title/generated_at/filters/columns/rows/summary` seragam,
 *   baris dipaginasi tetapi ringkasan atas SELURUH data terfilter, serta setiap
 *   filter (periode, gelombang, program, tanggal) benar-benar mempersempit;
 * - laporan dokumen: syarat wajib yang BELUM ada berkasnya ikut menjadi baris
 *   dan dirinci di `summary.missing`;
 * - CSV: BOM UTF-8, header = kunci kolom, jumlah baris = jumlah data, sel
 *   berkoma/berkutip dan berawalan formula lolos round-trip tanpa berubah, dan
 *   jenis tak dikenal ditolak 422;
 * - PDF: konten `%PDF`, ringan, untuk setiap jenis;
 * - otorisasi: `ppdb.report.view` untuk laporan, `ppdb.report.export` untuk
 *   CSV/PDF.
 */
class PpdbReportTest extends TestCase
{
    use RefreshDatabase;

    private PpdbPeriod $period;

    private PpdbPeriod $archived;

    private PpdbWave $waveOne;

    private PpdbWave $waveTwo;

    private PpdbProgram $ipa;

    private PpdbProgram $ips;

    private Gender $male;

    private Gender $female;

    private PaymentMethod $transfer;

    /** @var array<string, PpdbParentAccount> */
    private array $parents = [];

    /** @var array<string, PpdbApplicant> */
    private array $applicants = [];

    /** @var array<string, PpdbRegistration> */
    private array $registrations = [];

    /** @var array<string, PpdbDocumentRequirement> */
    private array $requirements = [];

    private PpdbSelectionStage $stageTest;

    private PpdbSelectionStage $stageInterview;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->fixtures();
    }

    // =================================================================
    // DASHBOARD
    // =================================================================

    public function test_dashboard_memakai_periode_terbaru_non_arsip_sebagai_bawaan(): void
    {
        $this->actingAsRole('panitia-ppdb');

        $response = $this->getJson('/api/v1/ppdb/dashboard')->assertOk();

        $this->assertSame('period', $response->json('data.scope'));
        $this->assertSame($this->period->id, $response->json('data.period.id'));
        $this->assertSame('PPDB-2026', $response->json('data.period.code'));
        $this->assertSame(40, $response->json('data.period.total_quota'));
        $this->assertSame(2, $response->json('data.period.wave_count'));
        $this->assertSame('PPDB 2026/2027', $response->json('data.filters.period.name'));

        // Semua numerik terisi (tidak ada null) di seluruh payload.
        $this->assertSame([], $this->nullNumbers($response->json('data')));
    }

    public function test_dashboard_statistik_mencakup_semua_kunci_status(): void
    {
        $this->actingAsRole('admin-sekolah');

        $data = $this->getJson('/api/v1/ppdb/dashboard')->assertOk()->json('data');

        $this->assertSame(5, $data['statistics']['total_registrations']);
        $this->assertSame(4, $data['statistics']['submitted_registrations']);
        $this->assertSame(2, $data['statistics']['accepted_registrations']);
        $this->assertSame(1, $data['statistics']['completed_registrations']);
        $this->assertSame(5, $data['statistics']['total_applicants']);
        $this->assertSame(3, $data['statistics']['total_parent_accounts']);
        $this->assertSame(5, $data['statistics']['total_documents']);
        $this->assertSame(3, $data['statistics']['total_invoices']);
        $this->assertSame(5, $data['statistics']['total_selection_participants']);

        $this->assertSame(PpdbRegistration::STATUSES, array_keys($data['statistics']['by_status']));
        $this->assertSame([
            'draft' => 1, 'submitted' => 1, 'under_review' => 1, 'need_revision' => 0,
            'verified' => 0, 'selection' => 0, 'passed' => 1, 'failed' => 0,
            'waitlisted' => 0, 're_registration' => 0, 'completed' => 1, 'cancelled' => 0,
        ], $data['statistics']['by_status']);

        $this->assertSame(PpdbRegistration::PAYMENT_STATUSES, array_keys($data['statistics']['by_payment_status']));
        $this->assertSame(['unpaid' => 2, 'pending' => 1, 'paid' => 2, 'failed' => 0, 'expired' => 0, 'cancelled' => 0, 'refunded' => 0], $data['statistics']['by_payment_status']);

        $this->assertSame(PpdbRegistration::DOCUMENT_STATUSES, array_keys($data['statistics']['by_document_status']));
        $this->assertSame(['not_uploaded' => 1, 'under_review' => 2, 'approved' => 2, 'rejected' => 0], $data['statistics']['by_document_status']);

        $this->assertSame(['passed' => 2, 'failed' => 0, 'waitlisted' => 0, 'none' => 3], $data['statistics']['by_selection_result']);

        $this->assertSame(PpdbParentAccount::STATUSES, array_keys($data['statistics']['parent_accounts']['by_status']));
        $this->assertSame(['pending_verification' => 1, 'active' => 1, 'inactive' => 0, 'blocked' => 1], $data['statistics']['parent_accounts']['by_status']);
        $this->assertSame(3, $data['statistics']['parent_accounts']['total']);
        $this->assertSame(3, $data['statistics']['parent_accounts']['with_registration']);

        $this->assertSame(['active' => 5, 'inactive' => 0], $data['statistics']['applicants']['by_status']);
        $this->assertSame(5, $data['statistics']['documents']['total_active']);
        $this->assertSame(3, $data['statistics']['documents']['approved']);
        $this->assertSame(2, $data['statistics']['documents']['awaiting_review']);
        $this->assertSame(0, $data['statistics']['documents']['rejected']);
    }

    public function test_dashboard_corong_dan_persentase_konversi(): void
    {
        $this->actingAsRole('panitia-ppdb');

        $funnel = $this->getJson('/api/v1/ppdb/dashboard')->assertOk()->json('data.funnel');

        $this->assertSame(['draft', 'submitted', 'verified', 'selection', 'passed', 'completed'], array_column($funnel['steps'], 'key'));
        $this->assertSame([5, 4, 2, 2, 2, 1], array_column($funnel['steps'], 'count'));
        $this->assertSame([100.0, 80.0, 40.0, 40.0, 40.0, 20.0], $this->floats(array_column($funnel['steps'], 'percent_of_start')));
        $this->assertSame([100.0, 80.0, 50.0, 100.0, 100.0, 50.0], $this->floats(array_column($funnel['steps'], 'conversion_percent')));
        $this->assertSame(20.0, $this->num($funnel['overall_conversion_percent']));
        $this->assertSame(5, $funnel['total']);
    }

    public function test_dashboard_keuangan_menjumlah_tagihan_terkumpul_dan_tunggakan(): void
    {
        $this->actingAsRole('bendahara');

        $financial = $this->getJson('/api/v1/ppdb/dashboard')->assertOk()->json('data.financial');

        $this->assertSame(3, $financial['invoice_count']);
        $this->assertSame(1000000.0, $this->num($financial['billed_amount']));
        $this->assertSame(1000000.0, $this->num($financial['net_billed_amount']));
        $this->assertSame(500000.0, $this->num($financial['collected_amount']));
        $this->assertSame(500000.0, $this->num($financial['outstanding_amount']));
        $this->assertSame(50000.0, $this->num($financial['discount_amount']));
        $this->assertSame(200000.0, $this->num($financial['awaiting_verification_amount']));
        $this->assertEqualsWithDelta(50.0, $financial['collection_percent'], 0.01);
        $this->assertSame(1, $financial['overdue_invoice_count']);
        $this->assertSame(1, $financial['paid_invoice_count']);
        $this->assertSame(333333.33, $this->num($financial['average_invoice_amount']));

        $this->assertSame(['manual' => ['count' => 1, 'amount' => 500000.0]],
            array_map(static fn (array $row): array => ['count' => $row['payments'], 'amount' => (float) $row['amount']],
                array_combine(array_column($financial['by_channel'], 'key'), $financial['by_channel'])));

        $this->assertSame('Transfer Bank', $financial['by_payment_method'][0]['label']);
        $this->assertSame(PpdbInvoice::STATUSES, array_keys($financial['by_invoice_status']));
        $this->assertSame(300000.0, $this->num($financial['by_invoice_status']['unpaid']['outstanding']));
    }

    public function test_dashboard_distribusi_kuota_dan_sebaran(): void
    {
        $this->actingAsRole('panitia-ppdb');

        $distribution = $this->getJson('/api/v1/ppdb/dashboard')->assertOk()->json('data.distribution');

        $this->assertSame(40, $distribution['total_quota']);
        $this->assertSame(2, $distribution['total_accepted']);
        $this->assertEqualsWithDelta(5.0, $distribution['filled_percent'], 0.01);

        $programs = array_combine(array_column($distribution['by_program'], 'code'), $distribution['by_program']);

        $this->assertSame([30, 10], [$programs['IPA']['quota'], $programs['IPS']['quota']]);
        $this->assertSame([3, 2], [$programs['IPA']['applications'], $programs['IPS']['applications']]);
        $this->assertSame([2, 0], [$programs['IPA']['accepted'], $programs['IPS']['accepted']]);
        $this->assertEqualsWithDelta(6.67, $programs['IPA']['filled_percent'], 0.01);
        $this->assertSame(0.0, $this->num($programs['IPS']['filled_percent']));
        $this->assertSame(28, $programs['IPA']['remaining_quota']);
        $this->assertSame(60.0, $this->num($programs['IPA']['share_percent']));

        $waves = array_combine(array_column($distribution['by_wave'], 'code'), $distribution['by_wave']);
        $this->assertSame([3, 2], [$waves['GW-1']['applications'], $waves['GW-2']['applications']]);
        // Gelombang tanpa kuota tidak dianggap penuh meski sudah ada pendaftar.
        $this->assertFalse($waves['GW-2']['has_quota']);
        $this->assertSame(0.0, $this->num($waves['GW-2']['filled_percent']));

        $genders = array_column($distribution['by_gender'], 'applicants', 'name');
        $this->assertSame(['Laki-laki' => 3, 'Perempuan' => 2], $genders);
    }

    public function test_dashboard_aksi_tertunda_mengandung_empat_item_dengan_route(): void
    {
        $this->actingAsRole('panitia-ppdb');

        $actions = $this->getJson('/api/v1/ppdb/dashboard')->assertOk()->json('data.pending_actions');

        $this->assertCount(4, $actions);
        $this->assertSame(
            ['verify_registration', 'verify_payment', 'review_document', 'score_selection'],
            array_column($actions, 'key')
        );
        $this->assertSame([2, 1, 2, 2], array_column($actions, 'count'));

        foreach ($actions as $action) {
            $this->assertNotEmpty($action['route_key']);
            $this->assertNotEmpty($action['label']);
            $this->assertArrayHasKey('period_id', $action['filters']);
            $this->assertSame($this->period->id, $action['filters']['period_id']);
        }

        $this->assertSame('submitted,under_review', $actions[0]['filters']['status']);
        $this->assertSame('document', $actions[2]['report_type']);
    }

    public function test_dashboard_tren_enam_keranjang_dan_konsisten_dengan_total(): void
    {
        $this->actingAsRole('panitia-ppdb');

        $trend = $this->getJson('/api/v1/ppdb/dashboard')->assertOk()->json('data.trend');

        $this->assertSame('monthly', $trend['granularity']);
        $this->assertCount(6, $trend['points']);
        $this->assertSame(5, $trend['total']);
        $this->assertSame(5, array_sum(array_column($trend['points'], 'registrations')));

        $keys = array_column($trend['points'], 'key');
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys);

        foreach ($trend['points'] as $point) {
            foreach (['registrations', 'submitted', 'verified', 'paid', 'completed'] as $metric) {
                $this->assertIsInt($point[$metric]);
            }
        }

        $weekly = $this->getJson('/api/v1/ppdb/dashboard?granularity=weekly')->assertOk()->json('data.trend');
        $this->assertSame('weekly', $weekly['granularity']);
        $this->assertCount(6, $weekly['points']);
        // Total selalu sama dengan jumlah titik yang tampil: enam minggu ke
        // belakang hanya memuat r3 (30 hari), r4 (10 hari), dan r5 (5 hari),
        // sedangkan r1/r2 jatuh di luar jendela tren mingguan.
        $this->assertSame(3, $weekly['total']);
        $this->assertSame(3, array_sum(array_column($weekly['points'], 'registrations')));
    }

    public function test_dashboard_filter_mempersempit_dan_dukungan_semua_periode(): void
    {
        $this->actingAsRole('panitia-ppdb');

        $all = $this->getJson('/api/v1/ppdb/dashboard?period_id=all')->assertOk()->json('data');
        $this->assertSame('all', $all['scope']);
        $this->assertNull($all['period']);
        $this->assertNull($all['filters']['period_id']);
        $this->assertSame(6, $all['statistics']['total_registrations']);
        $this->assertSame(1, $all['statistics']['by_status']['cancelled']);
        $this->assertSame(1999000.0, $this->num($all['financial']['billed_amount']));

        $wave = $this->getJson('/api/v1/ppdb/dashboard?wave_id='.$this->waveTwo->id)->assertOk()->json('data');
        $this->assertSame(2, $wave['statistics']['total_registrations']);
        $this->assertSame(200000.0, $this->num($wave['financial']['outstanding_amount']));
        $this->assertSame(1, $wave['statistics']['by_status']['draft']);

        $program = $this->getJson('/api/v1/ppdb/dashboard?program_id='.$this->ips->id)->assertOk()->json('data');
        $this->assertSame(2, $program['statistics']['total_registrations']);
        $this->assertSame('IPS', $program['filters']['program']['code']);
        // Hanya program terfilter yang muncul di distribusi.
        $this->assertSame(['IPS'], array_column($program['distribution']['by_program'], 'code'));

        $recent = $this->getJson('/api/v1/ppdb/dashboard?date_from='.now()->subDays(20)->toDateString())->assertOk()->json('data');
        $this->assertSame(2, $recent['statistics']['total_registrations']);
        $this->assertSame(1, $recent['statistics']['by_status']['under_review']);
        $this->assertSame(0, $recent['statistics']['by_status']['completed']);

        // Status ganda lewat daftar yang dipisah koma (deep-link aksi tertunda).
        $pending = $this->getJson('/api/v1/ppdb/dashboard?status=submitted,under_review')->assertOk()->json('data');
        $this->assertSame(2, $pending['statistics']['total_registrations']);
        $this->assertSame(0, $pending['statistics']['by_status']['draft']);
        $this->assertSame([2, 1, 1, 2], array_column($pending['pending_actions'], 'count'));
        // Aksi yang basisnya status pendaftaran tetap jujur pada filter: hanya
        // r3 & r4 yang tersisa, jadi dokumen tertinjau pun ikut mengecil.
        $this->assertSame(1, $pending['statistics']['documents']['awaiting_review']);
    }

    public function test_dashboard_ditolak_tanpa_permission(): void
    {
        $this->actingWithoutPermissions();

        $this->getJson('/api/v1/ppdb/dashboard')->assertForbidden();
    }

    // =================================================================
    // LAPORAN JSON
    // =================================================================

    public function test_laporan_pendaftaran_kolom_baris_dan_ringkasan(): void
    {
        $this->actingAsRole('panitia-ppdb');

        $data = $this->getJson('/api/v1/ppdb/reports/registration?period_id='.$this->period->id)->assertOk()->json('data');

        $this->assertSame('registration', $data['type']);
        $this->assertSame('Laporan Pendaftaran PPDB', $data['title']);
        $this->assertNotEmpty($data['generated_at']);
        foreach (['type', 'title', 'generated_at', 'filters', 'columns', 'rows', 'summary', 'pagination'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }

        $columns = array_column($data['columns'], 'key');
        foreach (['registration_number', 'applicant_name', 'period_name', 'wave_name', 'program_name', 'status', 'payment_status', 'document_status'] as $expected) {
            $this->assertContains($expected, $columns);
        }
        foreach ($data['columns'] as $column) {
            $this->assertArrayHasKey('label', $column);
            $this->assertArrayHasKey('type', $column);
        }

        $this->assertCount(5, $data['rows']);
        $this->assertSame(5, $data['pagination']['total']);
        $this->assertSame($columns, array_keys($data['rows'][0]));
        $this->assertSame('PPDB-2026-0001', $data['rows'][0]['registration_number']);
        $this->assertSame('completed', $data['rows'][0]['status']);
        $this->assertSame('MIPA', $data['rows'][0]['program_name']);
        $this->assertNotEmpty($data['rows'][0]['submitted_at']);
        $this->assertEmpty($data['rows'][4]['submitted_at']);

        $this->assertSame(5, $data['summary']['total_rows']);
        $this->assertSame(5, $data['summary']['applicants']);
        $this->assertSame(3, $data['summary']['parents']);
        $this->assertSame(4, $data['summary']['submitted']);
        $this->assertSame(2, $data['summary']['verified']);
        $this->assertSame(PpdbRegistration::STATUSES, array_keys($data['summary']['by_status']));
    }

    public function test_laporan_pendaftaran_paginasi_dan_filter_tanggal(): void
    {
        $this->actingAsRole('panitia-ppdb');

        $page = $this->getJson('/api/v1/ppdb/reports/registration?period_id='.$this->period->id.'&per_page=2&page=2')
            ->assertOk()->json('data');

        $this->assertCount(2, $page['rows']);
        $this->assertSame(2, $page['pagination']['current_page']);
        $this->assertSame(2, $page['pagination']['per_page']);
        $this->assertSame(5, $page['pagination']['total']);
        $this->assertSame(3, $page['pagination']['last_page']);
        // Ringkasan tetap atas SELURUH data terfilter, bukan hanya halaman ini.
        $this->assertSame(5, $page['summary']['total_rows']);

        $narrow = $this->getJson('/api/v1/ppdb/reports/registration?period_id='.$this->period->id.'&date_from='.now()->subDays(20)->toDateString())
            ->assertOk()->json('data');
        $this->assertCount(2, $narrow['rows']);
        $this->assertSame(2, $narrow['summary']['total_rows']);
        $this->assertSame(now()->subDays(20)->toDateString(), $narrow['filters']['date_from']);

        $wave = $this->getJson('/api/v1/ppdb/reports/registration?wave_id='.$this->waveOne->id)->assertOk()->json('data');
        $this->assertCount(3, $wave['rows']);
        $this->assertSame($this->waveOne->id, $wave['filters']['wave']['id']);
        $this->assertSame('Gelombang 1', $wave['filters']['wave']['name']);

        $program = $this->getJson('/api/v1/ppdb/reports/registration?program_id='.$this->ips->id)->assertOk()->json('data');
        $this->assertCount(2, $program['rows']);

        $status = $this->getJson('/api/v1/ppdb/reports/registration?status=passed,completed')->assertOk()->json('data');
        $this->assertCount(2, $status['rows']);
        // Baris diurutkan menurut pendaftaran, bukan menurut status → bandingkan
        // himpunan status tanpa mengikat urutannya.
        $this->assertEqualsCanonicalizing(['passed', 'completed'], array_values(array_unique(array_column($status['rows'], 'status'))));

        // Periode lain tidak ikut terbawa.
        $archived = $this->getJson('/api/v1/ppdb/reports/registration?period_id='.$this->archived->id)->assertOk()->json('data');
        $this->assertCount(1, $archived['rows']);
        $this->assertSame('PPDB-2025-0009', $archived['rows'][0]['registration_number']);

        // `per_page` di atas batas di-clamp (validasi 422 di atas 500).
        $this->getJson('/api/v1/ppdb/reports/registration?per_page=501')->assertUnprocessable();
    }

    public function test_laporan_pendaftaran_menolak_status_tak_dikenal(): void
    {
        $this->actingAsRole('panitia-ppdb');

        $this->getJson('/api/v1/ppdb/reports/registration?status=bukan-status')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->getJson('/api/v1/ppdb/reports/registration?period_id=999999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period_id']);
    }

    public function test_laporan_pembayaran_level_tagihan(): void
    {
        $this->actingAsRole('bendahara');

        $data = $this->getJson('/api/v1/ppdb/reports/payment?period_id='.$this->period->id)->assertOk()->json('data');

        $this->assertSame('payment', $data['type']);
        $this->assertContains('invoice_number', array_column($data['columns'], 'key'));
        $this->assertContains('outstanding_amount', array_column($data['columns'], 'key'));
        $this->assertCount(3, $data['rows']);

        // Urutan: transaksi terbaru lebih dulu (basis tanggal koalesen).
        $numbers = array_column($data['rows'], 'invoice_number');
        $this->assertSame(['INV-0003', 'INV-0002', 'INV-0001'], $numbers);

        $byNumber = array_combine($numbers, $data['rows']);
        $this->assertSame(500000.0, (float) $byNumber['INV-0001']['total_amount']);
        $this->assertSame(0.0, (float) $byNumber['INV-0001']['outstanding_amount']);
        $this->assertSame('paid', $byNumber['INV-0001']['status']);
        $this->assertSame('manual', $byNumber['INV-0001']['channels']);
        $this->assertSame(200000.0, (float) $byNumber['INV-0003']['total_amount']);
        $this->assertSame(200000.0, (float) $byNumber['INV-0003']['outstanding_amount']);
        $this->assertSame('midtrans', $byNumber['INV-0003']['channels']);
        // Nama orang tua ikut Laporan tagihan (untuk menindaklanjuti tunggakan).
        $this->assertNotEmpty($byNumber['INV-0002']['parent_name']);

        $this->assertSame(1000000.0, $this->num($data['summary']['billed_amount']));
        $this->assertSame(500000.0, $this->num($data['summary']['paid_amount']));
        $this->assertSame(500000.0, $this->num($data['summary']['outstanding_amount']));
        $this->assertSame(500000.0, $this->num($data['summary']['collected_amount']));
        $this->assertSame(200000.0, $this->num($data['summary']['awaiting_verification_amount']));
        $this->assertSame(1, $data['summary']['overdue_invoices']);
        $this->assertSame(PpdbInvoice::STATUSES, array_keys($data['summary']['by_status']));

        // Filter status tagihan & pembayaran mempersempit baris.
        $unpaid = $this->getJson('/api/v1/ppdb/reports/payment?period_id='.$this->period->id.'&invoice_status=unpaid,pending')
            ->assertOk()->json('data');
        $this->assertCount(2, $unpaid['rows']);
        $this->assertSame(500000.0, $this->num($unpaid['summary']['outstanding_amount']));

        // Tagihan periode arsip tidak terlihat pada cakupan periode aktif.
        $this->assertSame(3, $data['summary']['total_rows']);
        $all = $this->getJson('/api/v1/ppdb/reports/payment?period_id=all')->assertOk()->json('data');
        $this->assertCount(4, $all['rows']);
        $this->assertSame(1999000.0, $this->num($all['summary']['billed_amount']));
    }

    public function test_laporan_seleksi_per_pendaftaran_x_tahap(): void
    {
        $this->actingAsRole('panitia-ppdb');

        $data = $this->getJson('/api/v1/ppdb/reports/selection?period_id='.$this->period->id)->assertOk()->json('data');

        $this->assertSame('selection', $data['type']);
        $this->assertCount(5, $data['rows']);
        $this->assertContains('stage_name', array_column($data['columns'], 'key'));
        $this->assertContains('score', array_column($data['columns'], 'key'));

        $summary = $data['summary'];
        $this->assertSame(5, $summary['total_rows']);
        $this->assertSame(4, $summary['registrations']);
        $this->assertSame(2, $summary['stages']);
        $this->assertSame(3, $summary['scored']);
        // Rata-rata gabungan = (85 + 60 + 90) / 3.
        $this->assertEqualsWithDelta(78.33, $summary['average_score'], 0.01);

        $stages = array_column($summary['by_stage'], null, 'stage_name');
        $this->assertSame(4, $stages['Tes Akademik']['participants']);
        $this->assertEqualsWithDelta(72.5, $stages['Tes Akademik']['average_score'], 0.01);
        $this->assertEqualsWithDelta(90.0, $stages['Wawancara']['average_score'], 0.01);
        $this->assertSame(80.0, $this->num($stages['Tes Akademik']['passing_score']));
        $this->assertSame(1, $stages['Tes Akademik']['passed']);
        $this->assertSame(1, $stages['Tes Akademik']['failed']);

        // Filter tahap & hasil mempersempit baris.
        $stage = $this->getJson('/api/v1/ppdb/reports/selection?stage_id='.$this->stageInterview->id)->assertOk()->json('data');
        $this->assertCount(1, $stage['rows']);
        $this->assertSame('Wawancara', $stage['rows'][0]['stage_name']);

        $unscored = $this->getJson('/api/v1/ppdb/reports/selection?participant_status=invited,attended')
            ->assertOk()->json('data');
        $this->assertCount(2, $unscored['rows']);
        $this->assertSame(0, $unscored['summary']['scored']);
    }

    public function test_laporan_dokumen_menampilkan_syarat_yang_belum_ada(): void
    {
        $this->actingAsRole('panitia-ppdb');

        $data = $this->getJson('/api/v1/ppdb/reports/document?period_id='.$this->period->id)->assertOk()->json('data');

        $this->assertSame('document', $data['type']);
        $columns = array_column($data['columns'], 'key');
        $this->assertContains('requirement_name', $columns);
        $this->assertContains('status', $columns);

        // 5 berkas aktif + 3 persyaratan wajib yang belum disetor.
        $this->assertCount(8, $data['rows']);
        $sources = array_count_values(array_column($data['rows'], 'record_source'));
        $this->assertSame(5, $sources['uploaded']);
        $this->assertSame(3, $sources['missing']);

        $missing = array_values(array_filter($data['rows'], static fn (array $row): bool => $row['record_source'] === 'missing'));
        $this->assertSame('KTP Orang Tua', $missing[0]['requirement_name']);
        $this->assertSame('missing', $missing[0]['status']);
        $this->assertSame(1, (int) $missing[0]['required']);
        $this->assertEmpty($missing[0]['document_id']);

        $summary = $data['summary'];
        $this->assertSame(8, $summary['total_rows']);
        $this->assertSame(5, $summary['document_count']);
        // r1 punya SKL+KTP approved, r2 SKL approved (KTP masih tinjau), r3 SKL
        // under_review → 3 berkas approved dan 2 berkas menunggu tinjauan.
        $this->assertSame(3, $summary['approved']);
        $this->assertSame(2, $summary['awaiting_review']);
        $this->assertSame(4, $summary['registrations_subject']);
        $this->assertSame(3, $summary['missing']['required_documents_missing']);
        $this->assertSame(2, $summary['missing']['registrations_incomplete']);
        $this->assertSame(2, $summary['missing']['registrations_complete']);
        $this->assertEqualsWithDelta(50.0, $summary['missing']['completeness_percent'], 0.01);
        $this->assertNotEmpty($summary['missing']['basis']);

        $byRequirement = array_column($summary['missing']['by_requirement'], 'missing_count', 'requirement_code');
        // Urutan baris ringkasan tidak diikat; yang dijaga pasangan kode ↔ jumlah.
        ksort($byRequirement);
        $this->assertSame(['KTP' => 2, 'SKL' => 1], $byRequirement);

        // Tanpa baris "belum ada" hanya berkas nyata yang tersisa.
        $only = $this->getJson('/api/v1/ppdb/reports/document?period_id='.$this->period->id.'&include_missing=0')
            ->assertOk()->json('data');
        $this->assertCount(5, $only['rows']);
        $this->assertSame(0, $only['summary']['missing']['required_documents_missing']);

        // Filter status tinjau & persyaratan mempersempit.
        $approved = $this->getJson('/api/v1/ppdb/reports/document?review_status=approved')->assertOk()->json('data');
        $this->assertCount(3, $approved['rows']);

        $skl = $this->getJson('/api/v1/ppdb/reports/document?requirement_id='.$this->requirements['skl']->id)->assertOk()->json('data');
        $this->assertCount(4, $skl['rows']);
        $this->assertSame(3, array_count_values(array_column($skl['rows'], 'record_source'))['uploaded']);
    }

    public function test_otorisasi_laporan_csv_dan_pdf(): void
    {
        $this->actingWithoutPermissions();
        $this->getJson('/api/v1/ppdb/reports/registration')->assertForbidden();
        $this->getJson('/api/v1/ppdb/export?type=registration')->assertForbidden();
        $this->getJson('/api/v1/ppdb/reports/registration/pdf')->assertForbidden();

        // Bendahara: boleh melihat laporan, tidak boleh mengekspor.
        $this->actingAsRole('bendahara');
        $this->getJson('/api/v1/ppdb/reports/registration')->assertOk();
        $this->getJson('/api/v1/ppdb/export?type=registration')->assertForbidden();
        $this->getJson('/api/v1/ppdb/reports/payment/pdf')->assertForbidden();

        // Panitia & admin: lengkap.
        $this->actingAsRole('panitia-ppdb');
        foreach (['registration', 'payment', 'selection', 'document'] as $type) {
            $this->getJson('/api/v1/ppdb/reports/'.$type)->assertOk();
        }
        $this->getJson('/api/v1/ppdb/export?type=registration')->assertOk();
        $this->getJson('/api/v1/ppdb/reports/registration/pdf')->assertOk();

        $this->actingAsRole('admin-sekolah');
        $this->getJson('/api/v1/ppdb/export?type=applicants')->assertOk();
        $this->getJson('/api/v1/ppdb/reports/document/pdf')->assertOk();
    }

    // =================================================================
    // EKSPOR CSV
    // =================================================================

    public function test_ekspor_csv_berbom_dengan_header_dan_jumlah_baris_benar(): void
    {
        $this->actingAsRole('panitia-ppdb');

        $response = $this->getJson('/api/v1/ppdb/export?type=registration&period_id='.$this->period->id)->assertOk();

        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        // Nama berkas token ASCII boleh tidak memakai kutip (Symfony
        // makeDisposition) — yang dijaga: attachment + nama bertimestamp.
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertMatchesRegularExpression('/filename="?ppdb-registration-\d{8}-\d{6}\.csv"?/', $disposition);

        $content = $response->streamedContent();
        // BOM UTF-8: Excel membuka berkas ini dengan benar (aturan pemilik:
        // ekspor Excel = CSV yang kompatibel Excel).
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        $lines = explode("\n", trim(substr($content, 3)));
        // Semua baris laporan (tanpa paginasi) + satu baris header.
        $this->assertCount(6, $lines);

        // Kunci kolom CSV identik dengan kunci kolom JSON → hasil ekspor bisa
        // diolah ulang otomatis. Parsing memakai aturan yang sama dengan penulis
        // (kutip ganda, tanpa escape) sehingga sel berkutip tetap terbaca.
        $headers = $this->csvRow($lines[0]);
        $columns = $this->getJson('/api/v1/ppdb/reports/registration?period_id='.$this->period->id)
            ->assertOk()->json('data.columns');
        $this->assertSame(array_column($columns, 'key'), $headers);
        $this->assertContains('applicant_nisn', $headers);
        $this->assertContains('applicant_name', $headers);
        $this->assertNotContains('password', $headers);
        $this->assertNotContains('remember_token', $headers);
        $this->assertNotContains('parent_nik', $headers);
        $this->assertNotContains('nik', $headers);
        $this->assertNotContains('email', $headers);

        $first = $this->csvRow($lines[1]);
        $this->assertSame('PPDB-2026-0001', $first[array_search('registration_number', $headers, true)]);
        $this->assertSame('completed', $first[array_search('status', $headers, true)]);
        $this->assertSame('Andi Saputra', $first[array_search('applicant_name', $headers, true)]);
        $this->assertSame('0123456789', $first[array_search('applicant_nisn', $headers, true)]);

        // Cakupan berbeda → jumlah baris berbeda.
        $all = $this->getJson('/api/v1/ppdb/export?type=registration&period_id=all')->assertOk()->streamedContent();
        $this->assertCount(7, explode("\n", trim(substr($all, 3))));
    }

    public function test_ekspor_csv_mendukung_setiap_jenis(): void
    {
        $this->actingAsRole('admin-sekolah');

        // Tanpa `period_id` = semua periode; angka = jumlah baris data + header.
        $expected = [
            'registration' => 7,
            'payment' => 5,
            'selection' => 6,
            'document' => 9,
            'applicants' => 7,
            'parent_accounts' => 4,
        ];

        foreach ($expected as $type => $lines) {
            $content = $this->getJson('/api/v1/ppdb/export?type='.$type)->assertOk()->streamedContent();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $content, $type);
            $this->assertCount($lines, explode("\n", trim(substr($content, 3))), $type);
        }

        // Filter yang sama diperlakukan identik oleh CSV.
        $filtered = $this->getJson('/api/v1/ppdb/export?type=registration&period_id='.$this->archived->id)
            ->assertOk()->streamedContent();
        $this->assertCount(2, explode("\n", trim(substr($filtered, 3))));
        $this->assertStringContainsString('PPDB-2025-0009', $filtered);
    }

    public function test_ekspor_csv_melindungi_sel_berkoma_dan_formula(): void
    {
        $this->actingAsRole('panitia-ppdb');

        $this->applicants['satu']->update([
            'previous_school' => 'SMP 1 "Unggulan", Bandung',
            'address' => "=1+2+cmd|' /C calc'!A0",
        ]);

        $content = $this->getJson('/api/v1/ppdb/export?type=applicants&period_id=all')
            ->assertOk()->streamedContent();

        $lines = explode("\n", trim(substr($content, 3)));
        $headers = $this->csvRow($lines[0]);
        $nameAt = array_search('applicant_name', $headers, true);
        $schoolAt = array_search('previous_school', $headers, true);
        $addressAt = array_search('address', $headers, true);

        $row = null;

        foreach (array_slice($lines, 1) as $line) {
            $cells = $this->csvRow($line);

            if ($cells[$nameAt] === 'Andi Saputra') {
                $row = $cells;

                break;
            }
        }

        $this->assertNotNull($row, 'Baris calon tidak ditemukan pada ekspor applicants.');

        // Sel berkoma + berkutip ganda harus kembali utuh saat dibaca ulang —
        // ini yang membuat berkas tetap benar dibuka Excel (aturan pemilik:
        // ekspor = CSV kompatibel Excel).
        $this->assertSame('SMP 1 "Unggulan", Bandung', $row[$schoolAt]);
        // Formula injection dibendungi awalan kutip satu, isi aslinya tidak hilang.
        $this->assertStringStartsWith("'", $row[$addressAt]);
        $this->assertStringContainsString("=1+2+cmd|' /C calc'!A0", $row[$addressAt]);
    }

    public function test_ekspor_csv_menolak_jenis_tak_dikenal(): void
    {
        $this->actingAsRole('panitia-ppdb');

        $this->getJson('/api/v1/ppdb/export')->assertUnprocessable()->assertJsonValidationErrors(['type']);
        $this->getJson('/api/v1/ppdb/export?type=saldo')->assertUnprocessable()->assertJsonValidationErrors(['type']);
    }

    // =================================================================
    // PDF
    // =================================================================

    public function test_pdf_setiap_jenis_ringan_dan_terbaca(): void
    {
        $this->actingAsRole('panitia-ppdb');

        foreach (['registration', 'payment', 'selection', 'document', 'applicants', 'parent_accounts'] as $type) {
            $response = $this->getJson('/api/v1/ppdb/reports/'.$type.'/pdf?period_id='.$this->period->id)->assertOk();

            $this->assertSame('application/pdf', $response->headers->get('Content-Type'), $type);
            $this->assertMatchesRegularExpression('/filename="?ppdb-'.$type.'-\d{8}-\d{6}\.pdf"?/', (string) $response->headers->get('Content-Disposition'));

            $content = $response->getContent();
            $this->assertStringStartsWith('%PDF-1.', $content, $type);
            $this->assertStringContainsString('%%EOF', $content, $type);
            // Helvetica core font: tanpa penempelan font, berkas tetap kecil.
            $this->assertLessThan(50000, strlen($content), $type.' PDF terlalu besar');
            $this->assertGreaterThan(1000, strlen($content), $type.' PDF kosong');
        }
    }

    public function test_pdf_menolak_jenis_tak_dikenal(): void
    {
        $this->actingAsRole('panitia-ppdb');

        $this->getJson('/api/v1/ppdb/reports/saldo/pdf')->assertNotFound();
    }

    // =================================================================
    // FIXTURES
    // =================================================================

    private function fixtures(): void
    {
        $this->male = Gender::query()->create(['code' => 'M', 'name' => 'Laki-laki']);
        $this->female = Gender::query()->create(['code' => 'F', 'name' => 'Perempuan']);
        $this->transfer = PaymentMethod::query()->create(['code' => 'TRF', 'name' => 'Transfer Bank']);

        $this->archived = PpdbPeriod::query()->create([
            'code' => 'PPDB-2025', 'name' => 'PPDB 2025/2026', 'slug' => 'ppdb-2025',
            'status' => PpdbPeriod::STATUS_ARCHIVED,
            'registration_start' => '2025-01-05', 'registration_end' => '2025-06-30',
        ]);

        $this->period = PpdbPeriod::query()->create([
            'code' => 'PPDB-2026', 'name' => 'PPDB 2026/2027', 'slug' => 'ppdb-2026',
            'status' => PpdbPeriod::STATUS_OPEN,
            'registration_start' => now()->subMonths(4)->toDateString(),
            'registration_end' => now()->addMonth()->toDateString(),
            'selection_announcement_date' => now()->addMonths(2)->toDateString(),
        ]);

        $this->waveOne = PpdbWave::query()->create([
            'period_id' => $this->period->id, 'code' => 'GW-1', 'name' => 'Gelombang 1',
            'quota' => 25, 'priority' => 1,
        ]);
        $this->waveTwo = PpdbWave::query()->create([
            'period_id' => $this->period->id, 'code' => 'GW-2', 'name' => 'Gelombang 2',
            'quota' => null, 'priority' => 2,
        ]);

        $this->ipa = PpdbProgram::query()->create([
            'period_id' => $this->period->id, 'code' => 'IPA', 'name' => 'MIPA', 'quota' => 30, 'sort_order' => 1,
        ]);
        $this->ips = PpdbProgram::query()->create([
            'period_id' => $this->period->id, 'code' => 'IPS', 'name' => 'IPS', 'quota' => 10, 'sort_order' => 2,
        ]);
        $old = PpdbProgram::query()->create([
            'period_id' => $this->archived->id, 'code' => 'OLD', 'name' => 'Program Lama', 'quota' => 5,
        ]);

        $this->parents['sari'] = $this->parent('active');
        $this->parents['budi'] = $this->parent('pending_verification');
        $this->parents['blocked'] = $this->parent('blocked');

        $this->applicants['satu'] = $this->applicant('Andi Saputra', $this->male, 'sari', '3301010101010001', '0123456789');
        $this->applicants['dua'] = $this->applicant('Andi Wijaya', $this->male, 'sari', '3301010101010002', '0123456788');
        $this->applicants['tiga'] = $this->applicant('Bunga Citra', $this->female, 'budi', '3301010101010003', '0123456787');
        $this->applicants['empat'] = $this->applicant('Citra Ayu', $this->female, 'budi', '3301010101010004', '0123456786');
        $this->applicants['lima'] = $this->applicant('Dewi Lestari', $this->male, 'blocked', '3301010101010005', '0123456785');
        $this->applicants['enam'] = $this->applicant('Eko Budi', $this->male, 'blocked', '3301010101010006', '0123456784');

        // Pendaftaran aktif (periode PPDB-2026).
        $this->registrations['r1'] = $this->registration('PPDB-2026-0001', 'sari', 'satu', [
            'status' => 'completed', 'payment_status' => 'paid', 'document_status' => 'approved',
            'selection_result' => 'passed', 'wave_id' => $this->waveOne->id, 'program_id' => $this->ipa->id,
            'created_at' => now()->subMonths(3),
        ]);
        $this->registrations['r2'] = $this->registration('PPDB-2026-0002', 'sari', 'dua', [
            'status' => 'passed', 'payment_status' => 'paid', 'document_status' => 'approved',
            'selection_result' => 'passed', 'wave_id' => $this->waveOne->id, 'program_id' => $this->ipa->id,
            'created_at' => now()->subMonths(2),
        ]);
        $this->registrations['r3'] = $this->registration('PPDB-2026-0003', 'budi', 'tiga', [
            'status' => 'submitted', 'payment_status' => 'unpaid', 'document_status' => 'under_review',
            'wave_id' => $this->waveOne->id, 'program_id' => $this->ips->id,
            'created_at' => now()->subMonth(),
        ]);
        $this->registrations['r4'] = $this->registration('PPDB-2026-0004', 'budi', 'empat', [
            'status' => 'under_review', 'payment_status' => 'pending', 'document_status' => 'under_review',
            'wave_id' => $this->waveTwo->id, 'program_id' => $this->ips->id,
            'created_at' => now()->subDays(10),
        ]);
        $this->registrations['r5'] = $this->registration('PPDB-2026-0005', 'blocked', 'lima', [
            'status' => 'draft', 'payment_status' => 'unpaid', 'document_status' => 'not_uploaded',
            'wave_id' => $this->waveTwo->id, 'program_id' => $this->ipa->id,
            'created_at' => now()->subDays(5),
        ]);
        $this->registrations['r6'] = $this->registration('PPDB-2025-0009', 'blocked', 'enam', [
            'status' => 'cancelled', 'payment_status' => 'paid', 'document_status' => 'not_uploaded',
            'period_id' => $this->archived->id, 'wave_id' => null, 'program_id' => $old->id,
            'created_at' => now()->subDay(),
        ]);

        $this->invoices();
        $this->documents();
        $this->selection();
    }

    private function parent(string $status): PpdbParentAccount
    {
        $user = User::factory()->create(['is_active' => true]);

        return PpdbParentAccount::query()->create([
            'user_id' => $user->id,
            'account_status' => $status,
            'verified_at' => $status === 'active' ? now()->subMonths(4) : null,
        ]);
    }

    private function applicant(string $name, Gender $gender, string $parentKey, string $nik, string $nisn): PpdbApplicant
    {
        return PpdbApplicant::query()->create([
            'parent_account_id' => $this->parents[$parentKey]->id,
            'full_name' => $name,
            'gender_id' => $gender->id,
            'birth_place' => 'Bandung',
            'birth_date' => '2010-05-11',
            'nik' => $nik,
            'nisn' => $nisn,
            'previous_school' => 'SMP 1 Bandung',
            'target_program_id' => $this->ipa->id,
            'status' => 'active',
        ]);
    }

    /**
     * `submitted_at` & `verified_at` diturunkan dari status supaya konsisten
     * dengan corong: status apa pun selain draft/cancelled berarti sudah dikirim.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function registration(string $number, string $parentKey, string $applicantKey, array $attributes): PpdbRegistration
    {
        $createdAt = $attributes['created_at'] ?? now();
        $status = (string) $attributes['status'];
        $submitted = $status !== PpdbRegistration::STATUS_DRAFT && $status !== PpdbRegistration::STATUS_CANCELLED;

        return PpdbRegistration::query()->create($attributes + [
            'registration_number' => $number,
            'period_id' => $this->period->id,
            'applicant_id' => $this->applicants[$applicantKey]->id,
            'parent_account_id' => $this->parents[$parentKey]->id,
            'submitted_at' => $submitted ? $createdAt->copy()->addDay() : null,
            'verified_at' => in_array($status, ['verified', 'selection', 'passed', 'completed', 'failed'], true)
                ? $createdAt->copy()->addDays(2)
                : null,
            'completed_at' => $status === 'completed' ? $createdAt->copy()->addDays(9) : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function invoices(): void
    {
        $paid = PpdbInvoice::query()->create([
            'registration_id' => $this->registrations['r1']->id,
            'number' => 'INV-0001', 'type' => 'registration', 'amount' => 500000, 'discount_amount' => 0,
            'total_amount' => 500000, 'paid_amount' => 500000, 'status' => 'paid',
            'due_date' => now()->addDays(30)->toDateString(),
            'issued_at' => now()->subMonths(3), 'paid_at' => now()->subMonths(3)->addDays(2),
        ]);
        $unpaid = PpdbInvoice::query()->create([
            'registration_id' => $this->registrations['r3']->id,
            'number' => 'INV-0002', 'type' => 'registration', 'amount' => 300000, 'discount_amount' => 0,
            'total_amount' => 300000, 'paid_amount' => 0, 'status' => 'unpaid',
            'due_date' => now()->subDay()->toDateString(),
            'issued_at' => now()->subMonth(),
        ]);
        $pending = PpdbInvoice::query()->create([
            'registration_id' => $this->registrations['r4']->id,
            'number' => 'INV-0003', 'type' => 'registration', 'amount' => 250000, 'discount_amount' => 50000,
            'total_amount' => 200000, 'paid_amount' => 0, 'status' => 'pending',
            'due_date' => now()->addDays(10)->toDateString(),
            'issued_at' => now()->subDays(10),
        ]);
        PpdbInvoice::query()->create([
            'registration_id' => $this->registrations['r6']->id,
            'number' => 'INV-0004', 'type' => 'registration', 'amount' => 999000, 'discount_amount' => 0,
            'total_amount' => 999000, 'paid_amount' => 999000, 'status' => 'paid',
            'due_date' => now()->subYear()->toDateString(),
            'issued_at' => now()->subDay(), 'paid_at' => now()->subDay(),
        ]);

        PpdbPayment::query()->create([
            'invoice_id' => $paid->id, 'method' => 'manual', 'amount' => 500000, 'status' => 'paid',
            'payment_method_id' => $this->transfer->id, 'paid_at' => now()->subMonths(3)->addDays(2),
            'verified_at' => now()->subMonths(3)->addDays(3),
        ]);
        PpdbPayment::query()->create([
            'invoice_id' => $pending->id, 'method' => 'midtrans', 'amount' => 200000, 'status' => 'pending',
            'provider' => 'midtrans',
        ]);
        PpdbPayment::query()->create([
            'invoice_id' => $unpaid->id, 'method' => 'manual', 'amount' => 300000, 'status' => 'failed',
        ]);
    }

    private function documents(): void
    {
        foreach ([['skl', 'SKL', 'Ijazah/SKL', true], ['ktp', 'KTP', 'KTP Orang Tua', true], ['sertifikat', 'SKT', 'Sertifikat Prestasi', false]] as [$key, $code, $name, $required]) {
            $this->requirements[$key] = PpdbDocumentRequirement::query()->create([
                'period_id' => $this->period->id, 'code' => $code, 'name' => $name,
                'required' => $required, 'active' => true, 'order' => $required ? 1 : 9,
            ]);
        }

        PpdbDocumentRequirement::query()->create([
            'period_id' => $this->archived->id, 'code' => 'LAMA', 'name' => 'Persyaratan Lama',
            'required' => true, 'active' => true,
        ]);

        $this->document('r1', 'skl', 'approved');
        $this->document('r1', 'ktp', 'approved');
        $this->document('r2', 'skl', 'approved');
        $this->document('r2', 'ktp', 'uploaded');
        $this->document('r3', 'skl', 'under_review');
        // r3 belum menyetor KTP; r4 belum menyetor apa pun → baris "belum ada".
    }

    private function document(string $registrationKey, string $requirementKey, string $status): PpdbApplicantDocument
    {
        $registration = $this->registrations[$registrationKey];
        $requirement = $this->requirements[$requirementKey];

        return PpdbApplicantDocument::query()->create([
            'registration_id' => $registration->id,
            'applicant_id' => $registration->applicant_id,
            'requirement_id' => $requirement->id,
            'requirement_name' => $requirement->name,
            'version' => 1,
            'is_active' => true,
            'file_path' => 'ppdb/berkas-'.$registration->id.'-'.$requirement->id.'.pdf',
            'original_filename' => $requirement->code.'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 102400,
            'status' => $status,
            'uploaded_at' => $registration->created_at?->addDays(3),
            'reviewed_at' => $status === 'approved' ? $registration->created_at?->addDays(4) : null,
            'review_note' => $status === 'rejected' ? 'Bercah kabur' : null,
        ]);
    }

    private function selection(): void
    {
        $this->stageTest = PpdbSelectionStage::query()->create([
            'period_id' => $this->period->id, 'program_id' => null, 'type' => 'academic_test',
            'name' => 'Tes Akademik', 'scheduled_at' => now()->addDays(5), 'location' => 'Gedung A',
            'passing_score' => 80, 'status' => 'scheduled', 'order' => 1, 'quota' => 30,
        ]);
        $this->stageInterview = PpdbSelectionStage::query()->create([
            'period_id' => $this->period->id, 'program_id' => $this->ipa->id, 'type' => 'interview',
            'name' => 'Wawancara', 'scheduled_at' => now()->addDays(8), 'location' => 'Ruang 2',
            'passing_score' => 70, 'status' => 'scheduled', 'order' => 2,
        ]);

        $this->participant('r1', $this->stageTest, 'scored', 85, 'passed');
        $this->participant('r2', $this->stageTest, 'scored', 60, 'failed');
        $this->participant('r3', $this->stageTest, 'invited', null, null);
        $this->participant('r4', $this->stageTest, 'attended', null, null);
        $this->participant('r1', $this->stageInterview, 'scored', 90, 'passed');
    }

    private function participant(string $registrationKey, PpdbSelectionStage $stage, string $status, ?float $score, ?string $result): PpdbSelectionParticipant
    {
        return PpdbSelectionParticipant::query()->create([
            'stage_id' => $stage->id,
            'registration_id' => $this->registrations[$registrationKey]->id,
            'status' => $status,
            'score' => $score,
            'result' => $result,
            'attended_at' => in_array($status, ['attended', 'scored'], true) ? now()->subDays(2) : null,
            'created_at' => now()->subDays(4),
            'updated_at' => now()->subDays(4),
        ]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->syncRoles([$role]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function actingWithoutPermissions(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Baca satu baris CSV dengan aturan yang sama dipakai penulisnya
     * (pemisah koma, enclosure kutip ganda, tanpa escape).
     *
     * @return list<string|null>
     */
    private function csvRow(string $line): array
    {
        return str_getcsv($line, ',', '"', '');
    }

    /**
     * JSON PHP tidak membedakan 1000000.0 dengan 1000000 (float bulat seri
     * menjadi int), jadi numerik uang/persen dibandingkan sebagai float.
     */
    private function num(mixed $value): float
    {
        return (float) $value;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<float>
     */
    private function floats(array $values): array
    {
        return array_map(static fn (mixed $value): float => (float) $value, $values);
    }

    /**
     * Kumpulkan jalur bernilai null yang seharusnya numerik — pembukti bahwa
     * tidak ada angka null di payload dashboard.
     *
     * @param  mixed  $value
     * @return list<string>
     */
    private function nullNumbers(mixed $value, string $path = ''): array
    {
        $found = [];

        foreach ((array) $value as $key => $item) {
            $trail = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_array($item)) {
                $found = array_merge($found, $this->nullNumbers($item, $trail));

                continue;
            }

            if ($item === null && preg_match('/(count|total|amount|percent|registrations|applicants|quota|score|invoices|documents)$/i', $trail) === 1) {
                $found[] = $trail;
            }
        }

        return $found;
    }
}
