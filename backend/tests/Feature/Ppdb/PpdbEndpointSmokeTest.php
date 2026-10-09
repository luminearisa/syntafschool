<?php

namespace Tests\Feature\Ppdb;

use App\Models\Gender;
use App\Models\PpdbAnnouncement;
use App\Models\PpdbApplicantDocument;
use App\Models\PpdbDocumentRequirement;
use App\Models\PpdbInvoice;
use App\Models\PpdbParentAccount;
use App\Models\PpdbPeriod;
use App\Models\PpdbProgram;
use App\Models\PpdbRegistration;
use App\Models\PpdbRegistrationFee;
use App\Models\PpdbSelectionParticipant;
use App\Models\PpdbSelectionStage;
use App\Models\PpdbWave;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Ppdb\PpdbReportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sapuan asap (smoke) SELURUH endpoint PPDB: tidak boleh ada satu pun rute yang
 * membalas 5xx pada masukan yang sah.
 *
 * Ini bukan test benar-salahnya aturan bisnis (itu tugas `PpdbWorkflowTest` dan
 * `PpdbScenarioTest`), melainkan bukti bahwa tiap endpoint benar-benar BERJALAN:
 * 5 dari 6 cacat produksi PPDB yang ditemukan audit terakhir tidak terlihat oleh
 * test CRUD karena baru muncul saat endpoint dipanggil lewat HTTP dengan data
 * nyata (import resource yang hilang, `Collection::except()`, servis yang tidak
 * punya method, helper notifikasi yang salah baca kolom).
 *
 * Kontrak test ini:
 * - tiap GET dipanggil sebagai pemegang permission yang benar, dan sebagai
 *   aktor yang salah (401/403) — keduanya harus non-5xx;
 * - tiap POST/PUT/PATCH dikirim dengan payload realistis; 422 karena aturan
 *   bisnis diterima sebagai bukti endpoint bekerja, 500 tidak;
 * - endpoint state-machine didorong berurutan pada SATU pendaftaran
 *   (buat → kirim → transisi → verifikasi → seleksi → hasil → daftar ulang →
 *   konversi) supaya endpoint turunan punya data nyata, bukan tabel kosong;
 * - state hanya berubah lewat endpoint, tidak ada penulisan database.
 */
class PpdbEndpointSmokeTest extends TestCase
{
    use RefreshDatabase;

    private PpdbPeriod $period;

    private PpdbWave $wave;

    private PpdbProgram $program;

    private PpdbDocumentRequirement $requirement;

    private Gender $male;

    private Gender $female;

    private PaymentMethod $transfer;

    private User $panitia;

    private User $bendahara;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(RolePermissionSeeder::class);

        $this->male = Gender::query()->create(['code' => 'M', 'name' => 'Laki-laki']);
        $this->female = Gender::query()->create(['code' => 'F', 'name' => 'Perempuan']);
        $this->transfer = PaymentMethod::query()->create(['code' => 'TRF', 'name' => 'Transfer Bank']);

        $this->period = PpdbPeriod::query()->create([
            'code' => 'PPDB-SMOKE', 'name' => 'PPDB Sapuan Asap', 'slug' => 'ppdb-sapuan-asap',
            'status' => PpdbPeriod::STATUS_OPEN,
            'registration_start' => now()->subMonth()->toDateString(),
            'registration_end' => now()->addMonth()->toDateString(),
            'selection_announcement_date' => now()->addDays(10)->toDateString(),
            'appeal_start' => now()->subDay()->toDateString(),
            'appeal_end' => now()->addDays(5)->toDateString(),
            're_registration_start' => now()->subDay()->toDateString(),
            're_registration_end' => now()->addDays(14)->toDateString(),
        ]);

        $this->wave = PpdbWave::query()->create([
            'period_id' => $this->period->id, 'code' => 'GS-1', 'name' => 'Gelombang Sapuan',
            'quota' => 100, 'priority' => 1,
            'registration_start' => now()->subMonth()->toDateString(),
            'registration_end' => now()->addMonth()->toDateString(),
        ]);

        // Kuota lega: test ini mengejar codes respons, bukan penjaga kuota.
        $this->program = PpdbProgram::query()->create([
            'period_id' => $this->period->id, 'code' => 'SMK', 'name' => 'Program Sapuan',
            'quota' => 100, 'sort_order' => 1,
        ]);

        $this->requirement = PpdbDocumentRequirement::query()->create([
            'period_id' => $this->period->id, 'code' => 'KK-SMK', 'name' => 'Kartu Keluarga',
            'required' => true, 'active' => true, 'order' => 1,
            'file_types' => 'jpg,jpeg,png,pdf', 'max_size_kb' => 1024,
        ]);

        PpdbRegistrationFee::query()->create([
            'period_id' => $this->period->id, 'type' => PpdbRegistrationFee::TYPE_REGISTRATION,
            'name' => 'Biaya Pendaftaran Sapuan', 'amount' => 150000, 'required' => true, 'active' => true,
        ]);
        PpdbRegistrationFee::query()->create([
            'period_id' => $this->period->id, 'type' => PpdbRegistrationFee::TYPE_OTHER,
            'name' => 'Biaya Daftar Ulang Sapuan', 'amount' => 250000, 'required' => true, 'active' => true,
        ]);

        $this->panitia = $this->staff('panitia-ppdb');
        $this->bendahara = $this->staff('bendahara');
        $this->admin = $this->staff('admin-sekolah');
    }

    // =================================================================
    // PUBLIK + AUTENTIKASI
    // =================================================================

    public function test_endpoint_publik_dan_auth_tidak_membalas_5xx(): void
    {
        // Daftar periode publik: kosong maupun terisi harus 200.
        $this->refuse5xx($this->getJson('/api/v1/ppdb/public'), 'GET /ppdb/public');
        $this->refuse5xx(
            $this->getJson('/api/v1/ppdb/public?status=open&sort=registration_start&direction=asc&per_page=5'),
            'GET /ppdb/public (terfilter)',
        );

        $show = $this->getJson('/api/v1/ppdb/public/'.$this->period->slug);
        $show->assertOk();
        $this->refuse5xx($show, 'GET /ppdb/public/{slug}');

        // Slug tak dikenal = 404, bukan 500.
        $this->refuse5xx($this->getJson('/api/v1/ppdb/public/tidak-ada'), 'GET /ppdb/public/{slug} (404)');

        // Registrasi akun orang tua lewat HTTP.
        $registered = $this->postJson('/api/v1/ppdb/auth/register', [
            'name' => 'Wali Sapuan', 'email' => 'sapuan@publik.test', 'phone' => '081300000101',
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
            'address' => 'Jl. Sapuan No. 1, Bandung',
        ]);
        $registered->assertCreated();
        $this->refuse5xx($registered, 'POST /ppdb/auth/register');

        // Payload salah tetap 422 (endpoint berjalan), bukan 500.
        $this->refuse5xx(
            $this->postJson('/api/v1/ppdb/auth/register', ['name' => 'x', 'email' => 'bukan-email']),
            'POST /ppdb/auth/register (422)',
        );

        // Callback Midtrans tanpa tanda tangan sah → ditolak, tidak 5xx.
        $this->refuse5xx($this->postJson('/api/v1/ppdb/payments/midtrans/callback', [
            'transaction_details' => ['order_id' => 'PPDB-TIDAK-ADA', 'gross_amount' => 150000],
            'transaction_status' => 'settlement',
            'signature_key' => 'palsu',
        ]), 'POST /ppdb/payments/midtrans/callback');

        $this->refuse5xx(
            $this->postJson('/api/v1/ppdb/payments/midtrans/callback', []),
            'POST /ppdb/payments/midtrans/callback (body kosong)',
        );

        // Gerbang auth: tanpa token semua area portal & admin membalas 401.
        foreach ([
            'GET /api/v1/ppdb/portal/dashboard',
            'GET /api/v1/ppdb/portal/applicants',
            'GET /api/v1/ppdb/portal/notifications',
            'GET /api/v1/ppdb/registrations',
            'GET /api/v1/ppdb/invoices',
            'GET /api/v1/ppdb/dashboard',
        ] as $uri) {
            [$method, $path] = explode(' ', $uri);
            $this->refuse5xx($this->json(strtolower($method), $path), $uri.' (tanpa login)');
        }
    }

    // =================================================================
    // KONFIGURASI (CRUD PENUH)
    // =================================================================

    public function test_endpoint_konfigurasi_periodic_crud_tidak_membalas_5xx(): void
    {
        $this->as($this->panitia);

        // ---- Periode ------------------------------------------------
        $baru = $this->postJson('/api/v1/ppdb/periods', [
            'name' => 'Periode Sapuan Baru', 'code' => 'SMK-BARU', 'slug' => 'periode-sapuan-baru',
            'public_title' => 'PPDB Sapuan Baru', 'public_description' => 'Dibuat oleh test smoke.',
            'contact_name' => 'Panitia', 'contact_phone' => '081300000199',
            'contact_email' => 'panitia@sapuan.test', 'contact_address' => 'Jl. Sapuan',
            'faq' => [['question' => 'Kapan dibuka?', 'answer' => 'Sesuai jadwal.'] ],
            'registration_start' => now()->addMonth()->toDateString(),
            'registration_end' => now()->addMonths(2)->toDateString(),
            'status' => PpdbPeriod::STATUS_DRAFT,
        ]);
        $this->refuse5xx($baru, 'POST /ppdb/periods');

        $periodeBaru = PpdbPeriod::query()->findOrFail((int) $baru->json('data.id'));

        $this->refuse5xx($this->getJson('/api/v1/ppdb/periods'), 'GET /ppdb/periods');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/periods?status=draft&search=Sapuan'), 'GET /ppdb/periods (terfilter)');
        $this->refuse5xx($this->getJson("/api/v1/ppdb/periods/{$periodeBaru->id}"), 'GET /ppdb/periods/{id}');
        $this->refuse5xx(
            $this->putJson("/api/v1/ppdb/periods/{$periodeBaru->id}", ['name' => 'Periode Sapuan Direvisi']),
            'PUT /ppdb/periods/{id}',
        );

        // Publish sebelum ada program aktif: boleh 422, tidak boleh 500.
        $this->refuse5xx($this->postJson("/api/v1/ppdb/periods/{$periodeBaru->id}/publish"), 'POST /ppdb/periods/{id}/publish (tanpa program)');

        // Program aktif pada periode baru → publish boleh dicoba lagi.
        $programBaru = (int) $this->postJson('/api/v1/ppdb/programs', [
            'period_id' => $periodeBaru->id, 'code' => 'BARU', 'name' => 'Program Baru', 'quota' => 10,
        ])->assertCreated()->json('data.id');

        $this->refuse5xx($this->postJson("/api/v1/ppdb/periods/{$periodeBaru->id}/publish"), 'POST /ppdb/periods/{id}/publish');

        // ---- Gelombang ----------------------------------------------
        $waveId = (int) $this->postJson('/api/v1/ppdb/waves', [
            'period_id' => $periodeBaru->id, 'code' => 'GB-2', 'name' => 'Gelombang Cadangan',
            'quota' => 25, 'priority' => 2,
            'registration_start' => now()->addMonth()->toDateString(),
            'registration_end' => now()->addMonths(2)->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->refuse5xx($this->getJson('/api/v1/ppdb/waves?period_id='.$periodeBaru->id), 'GET /ppdb/waves');
        $this->refuse5xx($this->getJson("/api/v1/ppdb/waves/{$waveId}"), 'GET /ppdb/waves/{id}');
        $this->refuse5xx($this->putJson("/api/v1/ppdb/waves/{$waveId}", ['name' => 'Gelombang Cadangan Revisi']), 'PUT /ppdb/waves/{id}');
        $this->refuse5xx($this->deleteJson("/api/v1/ppdb/waves/{$waveId}"), 'DELETE /ppdb/waves/{id}');

        // ---- Program -------------------------------------------------
        $this->refuse5xx($this->getJson('/api/v1/ppdb/programs?period_id='.$this->period->id), 'GET /ppdb/programs');
        $this->refuse5xx($this->getJson("/api/v1/ppdb/programs/{$programBaru}"), 'GET /ppdb/programs/{id}');
        $this->refuse5xx($this->putJson("/api/v1/ppdb/programs/{$programBaru}", ['quota' => 12]), 'PUT /ppdb/programs/{id}');
        $this->refuse5xx($this->deleteJson("/api/v1/ppdb/programs/{$programBaru}"), 'DELETE /ppdb/programs/{id}');

        // ---- Field form builder --------------------------------------
        $text = (int) $this->postJson('/api/v1/ppdb/form-fields', [
            'period_id' => $this->period->id, 'field_key' => 'cita_cita', 'label' => 'Cita-cita',
            'type' => 'text', 'section' => 'tambahan', 'required' => true, 'order' => 3,
        ])->assertCreated()->json('data.id');

        $select = (int) $this->postJson('/api/v1/ppdb/form-fields', [
            'period_id' => $this->period->id, 'field_key' => 'agama_dipeluk', 'label' => 'Agama',
            'type' => 'select', 'section' => 'data_calon', 'required' => true, 'order' => 1,
            'options' => [['label' => 'Islam', 'value' => 'islam'], ['label' => 'Kristen', 'value' => 'kristen']],
        ])->assertCreated()->json('data.id');

        $this->refuse5xx($this->getJson('/api/v1/ppdb/form-fields?period_id='.$this->period->id), 'GET /ppdb/form-fields');
        $this->refuse5xx($this->getJson("/api/v1/ppdb/form-fields/{$text}"), 'GET /ppdb/form-fields/{id}');
        $this->refuse5xx($this->putJson("/api/v1/ppdb/form-fields/{$text}", ['label' => 'Cita-cita Revisi']), 'PUT /ppdb/form-fields/{id}');
        $this->refuse5xx($this->postJson('/api/v1/ppdb/form-fields/reorder', [
            'period_id' => $this->period->id,
            'items' => [['id' => $text, 'order' => 10], ['id' => $select, 'order' => 11]],
        ]), 'POST /ppdb/form-fields/reorder');
        $this->refuse5xx($this->postJson('/api/v1/ppdb/form-fields/reorder', [
            'period_id' => $this->period->id, 'field_ids' => [$select, $text],
        ]), 'POST /ppdb/form-fields/reorder (bentuk field_ids)');

        // ---- Pertanyaan tambahan -------------------------------------
        $questionId = (int) $this->postJson('/api/v1/ppdb/questions', [
            'period_id' => $this->period->id, 'code' => 'TANYA-1', 'type' => 'single_choice',
            'question' => 'Apakah calon tinggal bersama orang tua?', 'required' => true,
            'options' => [['label' => 'Ya', 'value' => 'ya'], ['label' => 'Tidak', 'value' => 'tidak']],
        ])->assertCreated()->json('data.id');

        $this->refuse5xx($this->getJson('/api/v1/ppdb/questions?period_id='.$this->period->id), 'GET /ppdb/questions');
        $this->refuse5xx($this->getJson("/api/v1/ppdb/questions/{$questionId}"), 'GET /ppdb/questions/{id}');
        $this->refuse5xx($this->putJson("/api/v1/ppdb/questions/{$questionId}", [
            'question' => 'Apakah calon tinggal bersama orang tua atau wali?',
            'options' => [
                ['id' => 1, 'label' => 'Ya', 'value' => 'ya'],
                ['label' => 'Bersama wali', 'value' => 'wali'],
            ],
        ]), 'PUT /ppdb/questions/{id}');

        // ---- Persyaratan dokumen -------------------------------------
        $docId = (int) $this->postJson('/api/v1/ppdb/document-requirements', [
            'period_id' => $this->period->id, 'code' => 'IJA-SMK', 'name' => 'Ijazah Sebelumnya',
            'required' => false, 'active' => true, 'order' => 2,
            'file_types' => 'pdf,jpg', 'max_size_kb' => 2048,
        ])->assertCreated()->json('data.id');

        $this->refuse5xx($this->getJson('/api/v1/ppdb/document-requirements?period_id='.$this->period->id), 'GET /ppdb/document-requirements');
        $this->refuse5xx($this->getJson("/api/v1/ppdb/document-requirements/{$docId}"), 'GET /ppdb/document-requirements/{id}');
        $this->refuse5xx($this->putJson("/api/v1/ppdb/document-requirements/{$docId}", ['name' => 'Ijazah Sebelumnya (legalisir)']), 'PUT /ppdb/document-requirements/{id}');
        $this->refuse5xx($this->deleteJson("/api/v1/ppdb/document-requirements/{$docId}"), 'DELETE /ppdb/document-requirements/{id}');

        // Persyaratan yang sudah dipakai berkas calon tidak boleh hilang (422).
        $this->refuse5xx($this->deleteJson("/api/v1/ppdb/document-requirements/{$this->requirement->id}"), 'DELETE /ppdb/document-requirements/{id} (terpakai)');

        // ---- Biaya ----------------------------------------------------
        $feeId = (int) $this->postJson('/api/v1/ppdb/fees', [
            'period_id' => $this->period->id, 'type' => PpdbRegistrationFee::TYPE_TEST,
            'name' => 'Biaya Tes Sapuan', 'amount' => 75000, 'required' => true, 'active' => true,
        ])->assertCreated()->json('data.id');

        $this->refuse5xx($this->getJson('/api/v1/ppdb/fees?period_id='.$this->period->id), 'GET /ppdb/fees');
        $this->refuse5xx($this->getJson("/api/v1/ppdb/fees/{$feeId}"), 'GET /ppdb/fees/{id}');
        $this->refuse5xx($this->putJson("/api/v1/ppdb/fees/{$feeId}", ['amount' => 80000]), 'PUT /ppdb/fees/{id}');
        $this->refuse5xx($this->deleteJson("/api/v1/ppdb/fees/{$feeId}"), 'DELETE /ppdb/fees/{id}');

        // Field & pertanyaan yang sudah ada jawabannya ikut diperiksa hapusnya.
        $this->refuse5xx($this->deleteJson("/api/v1/ppdb/form-fields/{$text}"), 'DELETE /ppdb/form-fields/{id}');
        $this->refuse5xx($this->deleteJson("/api/v1/ppdb/questions/{$questionId}"), 'DELETE /ppdb/questions/{id}');

        // Periode cadangan: gelombang & programnya sudah dilepas, jadi boleh dihapus.
        $this->refuse5xx($this->deleteJson("/api/v1/ppdb/periods/{$periodeBaru->id}"), 'DELETE /ppdb/periods/{id}');

        // Otorisasi: konfigurasi tertutup untuk orang tua dan bendahara.
        $parent = $this->registerParent('konfig@sapuan.test', '081300000111');

        $this->refuse5xx($this->as($parent['user'])->getJson('/api/v1/ppdb/periods'), 'GET /ppdb/periods (orang tua)');
        $this->refuse5xx($this->as($parent['user'])->postJson('/api/v1/ppdb/periods', ['name' => 'Tanpa hak']), 'POST /ppdb/periods (orang tua)');
        $this->refuse5xx($this->as($this->bendahara)->getJson('/api/v1/ppdb/form-fields'), 'GET /ppdb/form-fields (bendahara)');
    }

    // =================================================================
    // PORTAL ORANG TUA
    // =================================================================

    public function test_endpoint_portal_orang_tua_tidak_membalas_5xx(): void
    {
        $parent = $this->registerParent('portal@sapuan.test', '081300000102');

        // Konfigurasi formulir aktif → autosave & submit punya sesuatu untuk divalidasi.
        $this->as($this->panitia);
        $this->postJson('/api/v1/ppdb/form-fields', [
            'period_id' => $this->period->id, 'field_key' => 'asal_sekolah_smoke', 'label' => 'Asal Sekolah',
            'type' => 'text', 'section' => 'sekolah_asal', 'required' => true,
        ])->assertCreated();
        $questionId = (int) $this->postJson('/api/v1/ppdb/questions', [
            'period_id' => $this->period->id, 'code' => 'SMK-TANYA', 'type' => 'yes_no',
            'question' => 'Bersedia mengikuti kegiatan ekstrakurikuler?', 'required' => true,
        ])->assertCreated()->json('data.id');

        $this->as($parent['user']);

        $this->refuse5xx($this->getJson('/api/v1/ppdb/portal/dashboard'), 'GET /ppdb/portal/dashboard');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/portal/profile'), 'GET /ppdb/portal/profile');

        $this->refuse5xx($this->putJson('/api/v1/ppdb/portal/profile', [
            'name' => 'Wali Sapuan Diperbarui',
            'phone' => '081300000102',
            'gender_id' => $this->female->id,
            'address' => 'Jl. Sapuan No. 2, Bandung',
        ]), 'PUT /ppdb/portal/profile');

        $this->refuse5xx($this->getJson('/api/v1/ppdb/portal/dashboard'), 'GET /ppdb/portal/dashboard (berisi)');

        // ---- Calon (anak) ---------------------------------------------
        $this->refuse5xx($this->getJson('/api/v1/ppdb/portal/applicants'), 'GET /ppdb/portal/applicants');

        $applicantId = $this->addApplicant($parent, 'Anak Sapuan', $this->male, '3301010101010201', '0123000201');

        $this->refuse5xx($this->getJson("/api/v1/ppdb/portal/applicants/{$applicantId}"), 'GET /ppdb/portal/applicants/{id}');
        $this->refuse5xx($this->putJson("/api/v1/ppdb/portal/applicants/{$applicantId}", [
            'full_name' => 'Anak Sapuan Diperbarui', 'gender_id' => $this->male->id,
            'nik' => '3301010101010201', 'nisn' => '0123000201',
            'birth_place' => 'Bandung', 'birth_date' => '2010-05-01',
            'previous_school' => 'SMP Sapuan', 'address' => 'Jl. Sapuan No. 2',
        ]), 'PUT /ppdb/portal/applicants/{id}');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/portal/applicants/tidak-ada'), 'GET /ppdb/portal/applicants/{id} (404)');

        // ---- Pendaftaran ----------------------------------------------
        $registration = $this->createRegistration($parent, $applicantId);

        $this->refuse5xx($this->getJson('/api/v1/ppdb/portal/registrations'), 'GET /ppdb/portal/registrations');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/portal/registrations?per_page=5'), 'GET /ppdb/portal/registrations (paginasi)');
        $this->refuse5xx($this->getJson("/api/v1/ppdb/portal/registrations/{$registration}"), 'GET /ppdb/portal/registrations/{id}');
        $this->refuse5xx($this->getJson("/api/v1/ppdb/portal/registrations/{$registration}/documents"), 'GET /ppdb/portal/registrations/{id}/documents');

        // Autosave draf: jawaban field + pertanyaan + biodata calon sekaligus.
        $autosave = $this->putJson("/api/v1/ppdb/portal/registrations/{$registration}", [
            'fields' => ['asal_sekolah_smoke' => 'SMP Sapuan'],
            'questions' => [$questionId => 'yes'],
            'applicant' => ['nickname' => 'Sapuan Anak'],
        ]);
        $this->refuse5xx($autosave, 'PUT /ppdb/portal/registrations/{id}');
        $autosave->assertOk();

        // Kunci asing tebakan → 403 (isolasi data), bukan 500.
        $foreign = $this->registerParent('asing@sapuan.test', '081300000103');
        $foreignApplicant = $this->addApplicant($foreign, 'Anak Asing', $this->female, '3301010101010202', '0123000202');
        $foreignRegistration = $this->createRegistration($foreign, $foreignApplicant);

        foreach ([
            "/api/v1/ppdb/portal/registrations/{$foreignRegistration}",
            "/api/v1/ppdb/portal/registrations/{$foreignRegistration}/documents",
            "/api/v1/ppdb/portal/registrations/{$foreignRegistration}/payment",
            "/api/v1/ppdb/portal/registrations/{$foreignRegistration}/selection",
            "/api/v1/ppdb/portal/registrations/{$foreignRegistration}/appeals",
        ] as $uri) {
            $this->refuse5xx($this->as($parent['user'])->getJson($uri), "GET {$uri} (bukan miliknya)");
        }

        // ---- Dokumen + kirim ------------------------------------------
        $this->as($parent['user']);
        $this->uploadDocument($parent, $registration);

        $this->refuse5xx($this->getJson("/api/v1/ppdb/portal/registrations/{$registration}/documents"), 'GET /ppdb/portal/registrations/{id}/documents (terisi)');

        // Tagihan otomatis disiapkan saat halaman pembayaran dibuka.
        $payment = $this->getJson("/api/v1/ppdb/portal/registrations/{$registration}/payment");
        $this->refuse5xx($payment, 'GET /ppdb/portal/registrations/{id}/payment');

        $invoiceId = (int) collect((array) $payment->json('data.invoices'))->pluck('id')->first();

        // Pembayaran manual + bukti → pending, menunggu bendahara.
        $submitPayment = $this->postJson("/api/v1/ppdb/portal/invoices/{$invoiceId}/pay", [
            'amount' => 150000, 'method' => 'manual', 'payment_method_id' => $this->transfer->id,
            'reference' => 'SMOKE-'.uniqid(),
            'proof' => UploadedFile::fake()->create('bukti.jpg', 90, 'image/jpeg'),
        ]);
        $this->refuse5xx($submitPayment, 'POST /ppdb/portal/invoices/{id}/pay');

        // Jalur Midtrans: penyedia belum dikonfigurasi → 422 yang menjelaskan.
        $this->refuse5xx($this->postJson("/api/v1/ppdb/portal/invoices/{$invoiceId}/pay", [
            'amount' => 150000, 'method' => 'midtrans',
        ]), 'POST /ppdb/portal/invoices/{id}/pay (midtrans belum dikonfigurasi)');

        // Jalur unggah bukti HANYA ada di sisi portal. Klient pernah memanggil
        // /ppdb/invoices/{id}/pay (tanpa `portal`) yang tidak pernah terdaftar,
        // jadi pembuktiannya dikunci di sini: 404, bukan diam-diam 200.
        $this->postJson("/api/v1/ppdb/invoices/{$invoiceId}/pay", ['amount' => 1])->assertNotFound();

        $this->refuse5xx($this->postJson("/api/v1/ppdb/portal/registrations/{$registration}/submit"), 'POST /ppdb/portal/registrations/{id}/submit');
        $this->refuse5xx($this->getJson("/api/v1/ppdb/portal/registrations/{$registration}/selection"), 'GET /ppdb/portal/registrations/{id}/selection');
        $this->refuse5xx($this->getJson("/api/v1/ppdb/portal/registrations/{$registration}/appeals"), 'GET /ppdb/portal/registrations/{id}/appeals');

        // Sanggahan atas pendaftaran yang belum punya hasil → 422, bukan 500.
        $this->refuse5xx(
            $this->postJson("/api/v1/ppdb/portal/registrations/{$registration}/appeals", ['reason' => 'Saya ingin menyanggah hasil yang belum keluar.']),
            'POST /ppdb/portal/registrations/{id}/appeals (belum ada hasil)',
        );

        // ---- Notifikasi -------------------------------------------------
        $this->refuse5xx($this->getJson('/api/v1/ppdb/portal/notifications'), 'GET /api/v1/ppdb/portal/notifications');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/portal/notifications?unread_only=1'), 'GET /ppdb/portal/notifications (belum dibaca)');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/portal/notifications/unread-count'), 'GET /ppdb/portal/notifications/unread-count');

        // Staf tanpa akun PPDB: 403 bersih, bukan 500.
        $this->refuse5xx($this->as($this->panitia)->getJson('/api/v1/ppdb/portal/notifications'), 'GET /ppdb/portal/notifications (staf tanpa akun)');
        $this->refuse5xx($this->as($this->panitia)->getJson('/api/v1/ppdb/portal/dashboard'), 'GET /ppdb/portal/dashboard (staf tanpa akun)');
    }

    public function test_notifikasi_portal_dapat_dibaca_dan_ditandai_terbaca(): void
    {
        $parent = $this->registerParent('info@sapuan.test', '081300000104');
        $registration = $this->verifiedRegistration($parent, $this->addApplicant($parent, 'Anak Kabar', $this->male, '3301010101010203', '0123000203'));

        // Pengumuman terbit → notifikasi portal mendarat di akun orang tua ini.
        $this->as($this->panitia);
        $announcement = (int) $this->postJson('/api/v1/ppdb/announcements', [
            'period_id' => $this->period->id, 'title' => 'Kabar Sapuan', 'body' => 'Silakan periksa hasil seleksi.',
            'channel' => PpdbAnnouncement::CHANNEL_PORTAL,
        ])->assertCreated()->json('data.id');

        $publish = $this->postJson("/api/v1/ppdb/announcements/{$announcement}/publish");
        $this->refuse5xx($publish, 'POST /ppdb/announcements/{id}/publish');
        $this->assertGreaterThan(0, (int) $publish->json('data.delivered'), 'Publish mengirim notifikasi ke minimal satu orang tua.');

        $this->as($parent['user']);

        $inbox = $this->getJson('/api/v1/ppdb/portal/notifications?per_page=50');
        $inbox->assertOk();
        $rows = (array) $inbox->json('data');
        $this->assertNotEmpty($rows, 'Kotak masuk orang tua terisi setelah pengumuman terbit.');

        $unread = $this->getJson('/api/v1/ppdb/portal/notifications/unread-count');
        $this->refuse5xx($unread, 'GET /ppdb/portal/notifications/unread-count');
        $this->assertGreaterThan(0, (int) $unread->json('data.unread'));

        $first = (string) $rows[0]['id'];

        $this->refuse5xx($this->postJson("/api/v1/ppdb/portal/notifications/{$first}/read"), 'POST /ppdb/portal/notifications/{id}/read');
        $this->refuse5xx($this->postJson('/api/v1/ppdb/portal/notifications/tidak-ada/read'), 'POST /ppdb/portal/notifications/{id}/read (404)');

        // ID notifikasi milik orang tua lain → 403, bukan 500 maupun 200.
        $other = $this->registerParent('lain@sapuan.test', '081300000105');
        $this->refuse5xx($this->as($other['user'])->postJson("/api/v1/ppdb/portal/notifications/{$first}/read"), 'POST /ppdb/portal/notifications/{id}/read (milik orang lain)');

        $this->refuse5xx($this->as($parent['user'])->postJson('/api/v1/ppdb/portal/notifications/read-all'), 'POST /ppdb/portal/notifications/read-all');

        $after = $this->getJson('/api/v1/ppdb/portal/notifications/unread-count');
        $this->assertSame(0, (int) $after->json('data.unread'), 'read-all benar-benar menghabiskan kotak masuk.');
    }

    // =================================================================
    // ADMIN: PENDAFTARAN, DOKUMEN, CALON, AKUN
    // =================================================================

    public function test_endpoint_admin_pendaftaran_dan_verifikasi_tidak_membalas_5xx(): void
    {
        $satu = $this->registerParent('satu@sapuan.test', '081300000106');
        $dua = $this->registerParent('dua@sapuan.test', '081300000107');
        $tiga = $this->registerParent('tiga@sapuan.test', '081300000108');

        $rSatu = $this->submittedRegistration($satu, $this->addApplicant($satu, 'Anak Satu', $this->male, '3301010101010204', '0123000204'));
        $rDua = $this->submittedRegistration($dua, $this->addApplicant($dua, 'Anak Dua', $this->female, '3301010101010205', '0123000205'));
        $rTiga = $this->submittedRegistration($tiga, $this->addApplicant($tiga, 'Anak Tiga', $this->male, '3301010101010206', '0123000206'));

        $this->as($this->panitia);

        // ---- Daftar & detail pendaftaran --------------------------------
        $this->refuse5xx($this->getJson('/api/v1/ppdb/registrations'), 'GET /ppdb/registrations');
        $this->refuse5xx(
            $this->getJson('/api/v1/ppdb/registrations?period_id='.$this->period->id.'&status=submitted&per_page=10'),
            'GET /ppdb/registrations (terfilter)',
        );
        $this->refuse5xx($this->getJson("/api/v1/ppdb/registrations/{$rSatu}"), 'GET /ppdb/registrations/{id}');
        $this->refuse5xx($this->getJson("/api/v1/ppdb/registrations/{$rSatu}/history"), 'GET /ppdb/registrations/{id}/history');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/registrations/999999'), 'GET /ppdb/registrations/{id} (404)');

        // ---- Berkas: antrean + tinjauan ---------------------------------
        $this->refuse5xx($this->getJson('/api/v1/ppdb/documents'), 'GET /ppdb/documents');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/documents?registration_id='.$rSatu), 'GET /ppdb/documents (per pendaftaran)');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/documents?all_versions=1&status=uploaded&search=Anak'), 'GET /ppdb/documents (semua versi)');

        $documentId = (int) $this->documentsOf($rSatu)->first()['id'];

        // Transisi wajib `submitted → under_review` sebelum verifikasi.
        $this->refuse5xx(
            $this->postJson("/api/v1/ppdb/registrations/{$rSatu}/transition", ['status' => PpdbRegistration::STATUS_UNDER_REVIEW, 'note' => 'Diteliti.']),
            'POST /ppdb/registrations/{id}/transition',
        );

        $this->refuse5xx(
            $this->postJson("/api/v1/ppdb/documents/{$documentId}/review", ['status' => PpdbApplicantDocument::STATUS_APPROVED, 'note' => 'Sesuai.']),
            'POST /ppdb/documents/{id}/review',
        );
        $this->refuse5xx($this->postJson("/api/v1/ppdb/documents/{$documentId}/review", ['status' => 'bukan-status']), 'POST /ppdb/documents/{id}/review (422)');

        $this->refuse5xx($this->postJson("/api/v1/ppdb/registrations/{$rSatu}/verify", ['note' => 'Lengkap.']), 'POST /ppdb/registrations/{id}/verify');

        // ---- Revisi, penolakan, pembatalan ------------------------------
        $this->refuse5xx($this->postJson("/api/v1/ppdb/registrations/{$rDua}/transition", [
            'status' => PpdbRegistration::STATUS_UNDER_REVIEW, 'note' => 'Diteliti ulang.',
        ]), 'POST /ppdb/registrations/{id}/transition (kedua)');

        $this->refuse5xx($this->postJson("/api/v1/ppdb/registrations/{$rDua}/request-revision", [
            'note' => 'Ada berkas yang perlu diperbaiki.',
            'targets' => [
                ['type' => 'document', 'id' => $this->requirement->id, 'reason' => 'Kartu keluarga kabur.'],
                ['type' => 'field', 'key' => 'asal_sekolah', 'reason' => 'Tidak terbaca.'],
            ],
        ]), 'POST /ppdb/registrations/{id}/request-revision');

        // Setelah diminta revisi, orang tua boleh menyimpan draf lagi.
        $this->as($dua['user'])->putJson("/api/v1/ppdb/portal/registrations/{$rDua}", [
            'applicant' => ['nickname' => 'Dua Revisi'],
        ])->assertOk();

        $this->as($this->panitia);
        $this->refuse5xx($this->postJson("/api/v1/ppdb/registrations/{$rTiga}/transition", [
            'status' => PpdbRegistration::STATUS_UNDER_REVIEW, 'note' => 'Diteliti.',
        ]), 'POST /ppdb/registrations/{id}/transition (ketiga)');
        $this->refuse5xx($this->postJson("/api/v1/ppdb/registrations/{$rTiga}/reject", [
            'note' => 'Berkas tidak memenuhi syarat.',
        ]), 'POST /ppdb/registrations/{id}/reject');

        // Transisi ilegal → 422 dengan jalur yang benar.
        $this->refuse5xx($this->postJson("/api/v1/ppdb/registrations/{$rSatu}/transition", [
            'status' => PpdbRegistration::STATUS_DRAFT, 'note' => 'Mundur ke draf.',
        ]), 'POST /ppdb/registrations/{id}/transition (ilegal)');

        // Pembatalan final pada pendaftaran yang masih draft. Jalur cancel hanya
        // ada di sisi petugas (portal orang tua tidak punya route-nya), jadi
        // pelakunya dipasang ulang setelah helper pendaftaran di atas.
        $empat = $this->registerParent('empat@sapuan.test', '081300000109');
        $rEmpat = $this->createRegistration($empat, $this->addApplicant($empat, 'Anak Empat', $this->female, '3301010101010207', '0123000207'));
        $cancelled = $this->as($this->panitia)->postJson("/api/v1/ppdb/registrations/{$rEmpat}/cancel", [
            'note' => 'Orang tua membatalkan diri.',
        ]);
        $this->refuse5xx($cancelled, 'POST /ppdb/registrations/{id}/cancel');
        $cancelled->assertOk();

        // ---- Calon & akun orang tua sisi admin ---------------------------
        $this->refuse5xx($this->getJson('/api/v1/ppdb/applicants'), 'GET /ppdb/applicants');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/applicants?period_id='.$this->period->id.'&search=Anak'), 'GET /ppdb/applicants (terfilter)');

        $calon = (int) PpdbRegistration::query()->findOrFail($rSatu)->applicant_id;

        // Halaman calon di admin membuka detail lewat ID. Rutenya pernah tidak
        // ada sama sekali (hanya index/update/destroy) sehingga tombol "lihat"
        // mati 404 — ditegakkan di sini agar tidak balik lagi.
        $detailCalon = $this->getJson("/api/v1/ppdb/applicants/{$calon}");
        $this->refuse5xx($detailCalon, 'GET /ppdb/applicants/{id}');
        $detailCalon->assertOk();
        $detailCalon->assertJsonPath('data.id', $calon);
        // `registrations` ikut terbawa karena relasinya dimuat resource induk.
        $this->assertNotEmpty($detailCalon->json('data.registrations'), 'Detail calon admin harus memuat pendaftarannya');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/applicants/tidak-ada'), 'GET /ppdb/applicants/{id} (404)');

        // Ubah/hapus calon digrup `permission:ppdb.update`, yang tidak dipegang
        // panitia — jadi dijalankan sebagai admin supaya benar-benar tereksekusi.
        $ubah = $this->as($this->admin)->putJson("/api/v1/ppdb/applicants/{$calon}", [
            'full_name' => 'Anak Satu', 'gender_id' => $this->male->id,
            'nik' => '3301010101010204', 'nisn' => '0123000204',
            'birth_place' => 'Bandung', 'birth_date' => '2010-04-01',
            'previous_school' => 'SMP Sapuan', 'address' => 'Jl. Sapuan No. 1',
            'notes' => 'Diperbarui panitia.',
        ]);
        $this->refuse5xx($ubah, 'PUT /ppdb/applicants/{id}');
        $this->assertSame(200, $ubah->getStatusCode(), 'Pemegang ppdb.update dapat mengubah calon: '.$ubah->getContent());

        // Calon tanpa pendaftaran boleh dihapus.
        $anakLima = $this->registerParent('lima@sapuan.test', '081300000110');
        $calonHapus = $this->addApplicant($anakLima, 'Anak Lima', $this->male, '3301010101010208', '0123000208');

        $hapus = $this->as($this->admin)->deleteJson("/api/v1/ppdb/applicants/{$calonHapus}");
        $this->refuse5xx($hapus, 'DELETE /ppdb/applicants/{id}');
        $this->assertSame(200, $hapus->getStatusCode(), 'Calon bebas dapat dihapus: '.$hapus->getContent());
        $this->refuse5xx($this->deleteJson("/api/v1/ppdb/applicants/{$calon}"), 'DELETE /ppdb/applicants/{id} (terpakai)');

        // Panitia tanpa `ppdb.update` → 403, bukan 500.
        $this->as($this->panitia)->deleteJson("/api/v1/ppdb/applicants/{$calon}")->assertForbidden();

        // Akun orang tua digrup `ppdb.account.manage` — panitia memegangnya.
        $this->as($this->panitia);
        $this->refuse5xx($this->getJson('/api/v1/ppdb/parent-accounts'), 'GET /ppdb/parent-accounts');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/parent-accounts?account_status=active&search=satu@sapuan.test'), 'GET /ppdb/parent-accounts (terfilter)');

        $searched = $this->getJson('/api/v1/ppdb/parent-accounts?search=satu@sapuan.test');
        $this->refuse5xx($searched, 'GET /ppdb/parent-accounts (search email)');
        $searched->assertOk();
        // Kunci barisnya `parent_account_id` (bukan `id`) — bentuk bakunya
        // `PpdbParentAccountResource::row()` yang dipakai portal juga.
        $accountId = (int) $searched->json('data.0.parent_account_id');
        $this->assertGreaterThan(0, $accountId, 'Pencarian akun orang tua menemukan akun yang didaftarkan. '.$searched->getContent());

        $this->refuse5xx($this->getJson("/api/v1/ppdb/parent-accounts/{$accountId}"), 'GET /ppdb/parent-accounts/{id}');
        $this->refuse5xx($this->postJson("/api/v1/ppdb/parent-accounts/{$accountId}/status", [
            'account_status' => PpdbParentAccount::STATUS_ACTIVE,
            'notes' => 'Diverifikasi panitia.',
        ]), 'POST /ppdb/parent-accounts/{id}/status');
        $this->refuse5xx($this->postJson("/api/v1/ppdb/parent-accounts/{$accountId}/reset-password"), 'POST /ppdb/parent-accounts/{id}/reset-password');

        // Bendahara & orang tua tidak memegang permission akun.
        $this->refuse5xx($this->as($this->bendahara)->getJson('/api/v1/ppdb/parent-accounts'), 'GET /ppdb/parent-accounts (bendahara)');
    }

    // =================================================================
    // ADMIN: PEMBAYARAN
    // =================================================================

    public function test_endpoint_pembayaran_dan_tagihan_tidak_membalas_5xx(): void
    {
        $pembayar = $this->registerParent('bayar@sapuan.test', '081300000112');
        $ditolak = $this->registerParent('tolak@sapuan.test', '081300000113');

        $rSatu = $this->submittedRegistration($pembayar, $this->addApplicant($pembayar, 'Anak Bayar', $this->male, '3301010101010209', '0123000209'));
        $rDua = $this->submittedRegistration($ditolak, $this->addApplicant($ditolak, 'Anak Tolak', $this->female, '3301010101010210', '0123000210'));

        // Dua pembayaran manual menunggu keputusan bendahara.
        $firstPayment = $this->payManual($pembayar, $rSatu, 150000);
        $secondPayment = $this->payManual($ditolak, $rDua, 150000);

        $this->as($this->panitia);

        $this->refuse5xx($this->getJson('/api/v1/ppdb/invoices'), 'GET /ppdb/invoices');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/invoices?period_id='.$this->period->id.'&status=pending'), 'GET /ppdb/invoices (terfilter)');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/payments'), 'GET /ppdb/payments');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/payments?status=pending&period_id='.$this->period->id), 'GET /ppdb/payments (terfilter)');

        $invoiceId = (int) $this->getJson('/api/v1/ppdb/invoices?period_id='.$this->period->id)->json('data.0.id');
        $this->assertGreaterThan(0, $invoiceId);

        $this->refuse5xx($this->getJson("/api/v1/ppdb/invoices/{$invoiceId}"), 'GET /ppdb/invoices/{id}');

        // Pembuatan tagihan idempoten: jenis yang sama dipakai kembali.
        $buat = $this->postJson('/api/v1/ppdb/invoices', [
            'registration_id' => $rSatu, 'type' => PpdbInvoice::TYPE_REGISTRATION,
        ]);
        $this->refuse5xx($buat, 'POST /ppdb/invoices (idempoten)');

        $this->refuse5xx($this->postJson('/api/v1/ppdb/invoices', ['registration_id' => 999999]), 'POST /ppdb/invoices (422)');

        // Pengingat pembayaran: sekali terkirim, duplikatnya ditahan.
        $this->refuse5xx($this->postJson("/api/v1/ppdb/invoices/{$invoiceId}/remind"), 'POST /ppdb/invoices/{id}/remind');
        $this->refuse5xx($this->postJson("/api/v1/ppdb/invoices/{$invoiceId}/remind"), 'POST /ppdb/invoices/{id}/remind (kedua kali)');

        // Penutupan tagihan: beralasan, dan penjaga status ada di layanan.
        $this->refuse5xx($this->postJson("/api/v1/ppdb/invoices/{$invoiceId}/cancel", [
            'reason' => 'Tagihan ganda, dibatalkan oleh petugas.',
        ]), 'POST /ppdb/invoices/{id}/cancel');
        $this->refuse5xx($this->postJson("/api/v1/ppdb/invoices/{$invoiceId}/refund"), 'POST /ppdb/invoices/{id}/refund (tanpa alasan, 422)');
        $this->refuse5xx($this->postJson("/api/v1/ppdb/invoices/{$invoiceId}/refund", [
            'reason' => 'Mencoba mengembalikan dana tagihan yang belum dibayar.',
        ]), 'POST /ppdb/invoices/{id}/refund (belum ada pembayaran)');

        // Tidak ada rute ubah/hapus pada tagihan: jawaban 405, bukan 500.
        $this->putJson("/api/v1/ppdb/invoices/{$invoiceId}", ['note' => 'ubah'])->assertStatus(405);
        $this->deleteJson("/api/v1/ppdb/invoices/{$invoiceId}")->assertStatus(405);

        // ---- Verifikasi & penolakan pembayaran ---------------------------
        $this->as($this->bendahara);

        $verify = $this->postJson("/api/v1/ppdb/payments/{$firstPayment}/verify", [
            'note' => 'Uang sudah masuk rekening sekolah.',
            'paid_at' => now()->toDateString(),
        ]);
        $this->refuse5xx($verify, 'POST /ppdb/payments/{id}/verify');

        $reject = $this->postJson("/api/v1/ppdb/payments/{$secondPayment}/reject", [
            'reason' => 'Nominal pada buktitransfer tidak cocok dengan tagihan.',
        ]);
        $this->refuse5xx($reject, 'POST /ppdb/payments/{id}/reject');

        // Pembayaran yang sudah diputus tidak dapat dinilai ulang.
        $this->refuse5xx($this->postJson("/api/v1/ppdb/payments/{$firstPayment}/reject", [
            'reason' => 'Mencoba membatalkan pembayaran yang sudah lunas.',
        ]), 'POST /ppdb/payments/{id}/reject (sudah diverifikasi)');

        // panitia juga memegang hak verifikasi pembayaran; orang tua tidak.
        $ketiga = $this->registerParent('ketiga-bayar@sapuan.test', '081300000114');
        $rTiga = $this->submittedRegistration($ketiga, $this->addApplicant($ketiga, 'Anak Bayar Tiga', $this->male, '3301010101010211', '0123000211'));
        $thirdPayment = $this->payManual($ketiga, $rTiga, 150000);

        $this->refuse5xx($this->as($ketiga['user'])->postJson("/api/v1/ppdb/payments/{$thirdPayment}/verify", ['note' => 'Verifikasi oleh orang tuanya sendiri.']), 'POST /ppdb/payments/{id}/verify (orang tua)');
        $this->refuse5xx($this->as($this->panitia)->postJson("/api/v1/ppdb/payments/{$thirdPayment}/verify", ['note' => 'Diverifikasi panitia.']), 'POST /ppdb/payments/{id}/verify (panitia)');
    }

    // =================================================================
    // ADMIN: SELEKSI, PENGUMUMAN, DAFTAR ULANG, KONVERSI
    // =================================================================

    public function test_endpoint_seleksi_pengumuman_dan_konversi_tidak_membalas_5xx(): void
    {
        $parent = $this->registerParent('seleksi@sapuan.test', '081300000115');
        $registration = $this->verifiedRegistration($parent, $this->addApplicant($parent, 'Anak Seleksi', $this->male, '3301010101010212', '0123000212'));

        $this->as($this->panitia);

        $test = (int) $this->postJson('/api/v1/ppdb/selection-stages', [
            'period_id' => $this->period->id, 'name' => 'Tes Akademik Sapuan',
            'type' => PpdbSelectionStage::TYPE_ACADEMIC_TEST, 'weight' => 60,
            'scheduled_at' => now()->addDays(3)->toDateTimeString(), 'location' => 'Ruang Ujian',
            'passing_score' => 70,
        ])->assertCreated()->json('data.id');

        $interview = (int) $this->postJson('/api/v1/ppdb/selection-stages', [
            'period_id' => $this->period->id, 'name' => 'Wawancara Sapuan',
            'type' => PpdbSelectionStage::TYPE_INTERVIEW, 'weight' => 40,
            'scheduled_at' => now()->addDays(4)->toDateTimeString(), 'location' => 'Ruang Wawancara',
            'passing_score' => 60,
        ])->assertCreated()->json('data.id');

        $this->refuse5xx($this->getJson('/api/v1/ppdb/selection-stages'), 'GET /ppdb/selection-stages');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/selection-stages?period_id='.$this->period->id), 'GET /ppdb/selection-stages (terfilter)');
        $this->refuse5xx($this->getJson("/api/v1/ppdb/selection-stages/{$test}"), 'GET /ppdb/selection-stages/{id}');
        $this->refuse5xx($this->putJson("/api/v1/ppdb/selection-stages/{$interview}", [
            'period_id' => $this->period->id, 'name' => 'Wawancara Orang Tua',
            'type' => PpdbSelectionStage::TYPE_INTERVIEW, 'weight' => 40, 'location' => 'Ruang 2',
        ]), 'PUT /ppdb/selection-stages/{id}');

        // Tahap kosong boleh dihapus; peserta menunggu di tahap yang dipakai.
        $kosong = (int) $this->postJson('/api/v1/ppdb/selection-stages', [
            'period_id' => $this->period->id, 'name' => 'Tahap Kosong', 'type' => PpdbSelectionStage::TYPE_OTHER,
            'weight' => 0,
        ])->assertCreated()->json('data.id');
        $this->refuse5xx($this->deleteJson("/api/v1/ppdb/selection-stages/{$kosong}"), 'DELETE /ppdb/selection-stages/{id} (kosong)');

        $this->refuse5xx($this->getJson('/api/v1/ppdb/selection-stages/weights?period_id='.$this->period->id), 'GET /ppdb/selection-stages/weights');
        $this->refuse5xx($this->putJson('/api/v1/ppdb/selection-stages/weights', [
            'period_id' => $this->period->id,
            'weights' => [['stage_id' => $test, 'weight' => 60], ['stage_id' => $interview, 'weight' => 40]],
        ]), 'PUT /ppdb/selection-stages/weights');

        $this->refuse5xx($this->getJson("/api/v1/ppdb/selection-stages/{$test}/candidates"), 'GET /ppdb/selection-stages/{id}/candidates');

        $daftar = $this->postJson("/api/v1/ppdb/selection-stages/{$test}/participants", [
            'registration_ids' => [$registration, 999999],
        ]);
        $this->refuse5xx($daftar, 'POST /ppdb/selection-stages/{id}/participants');
        $this->assertNotEmpty($daftar->json('data.created'), 'Pendaftaran terverifikasi menjadi peserta.');

        $this->refuse5xx($this->getJson("/api/v1/ppdb/selection-stages/{$test}/participants"), 'GET /ppdb/selection-stages/{id}/participants');

        $participant = (int) $daftar->json('data.created.0.id');

        $this->refuse5xx($this->postJson("/api/v1/ppdb/selection-participants/{$participant}/attend", [
            'attended_at' => now()->toDateTimeString(), 'note' => 'Hadir tepat waktu.',
        ]), 'POST /ppdb/selection-participants/{id}/attend');

        $scored = $this->postJson("/api/v1/ppdb/selection-participants/{$participant}/score", [
            'score' => 88, 'status' => PpdbSelectionParticipant::STATUS_SCORED, 'note' => 'Nilai bagus.',
        ]);
        $this->refuse5xx($scored, 'POST /ppdb/selection-participants/{id}/score');
        $this->assertNotNull($scored->json('data.composite'), 'Skor gabungan ikut dihitung setelah bobot grup pas 100%.');

        // Nilai di luar skala → 422, bukan 500.
        $this->refuse5xx($this->postJson("/api/v1/ppdb/selection-participants/{$participant}/score", ['score' => 5000]), 'POST /ppdb/selection-participants/{id}/score (422)');

        // Tahap dengan peserta tidak boleh dihapus.
        $this->refuse5xx($this->deleteJson("/api/v1/ppdb/selection-stages/{$test}"), 'DELETE /ppdb/selection-stages/{id} (berpeserta)');

        // ---- Pengumuman --------------------------------------------------
        $this->refuse5xx($this->getJson('/api/v1/ppdb/announcements'), 'GET /ppdb/announcements');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/announcements?period_id='.$this->period->id.'&status=draft'), 'GET /ppdb/announcements (terfilter)');

        $draf = (int) $this->postJson('/api/v1/ppdb/announcements', [
            'period_id' => $this->period->id, 'title' => 'Draf Pengumuman', 'body' => 'Isi draf.',
            'channel' => PpdbAnnouncement::CHANNEL_EMAIL,
        ])->assertCreated()->json('data.id');

        $this->refuse5xx($this->getJson("/api/v1/ppdb/announcements/{$draf}"), 'GET /ppdb/announcements/{id}');
        $this->refuse5xx($this->putJson("/api/v1/ppdb/announcements/{$draf}", ['title' => 'Draf Pengumuman Revisi']), 'PUT /ppdb/announcements/{id}');

        // Mengubah status jadi published lewat PUT ditolak 422 (jalurnya /publish).
        $this->refuse5xx($this->putJson("/api/v1/ppdb/announcements/{$draf}", ['status' => PpdbAnnouncement::STATUS_PUBLISHED]), 'PUT /ppdb/announcements/{id} (status langsung)');

        $this->refuse5xx($this->postJson("/api/v1/ppdb/announcements/{$draf}/archive"), 'POST /ppdb/announcements/{id}/archive');
        $this->refuse5xx($this->deleteJson("/api/v1/ppdb/announcements/{$draf}"), 'DELETE /ppdb/announcements/{id}');

        // ---- Penetapan hasil --------------------------------------------
        $result = $this->postJson("/api/v1/ppdb/registrations/{$registration}/result", [
            'result' => PpdbRegistration::STATUS_PASSED, 'note' => 'Diterima.',
        ]);
        $this->refuse5xx($result, 'POST /ppdb/registrations/{id}/result');

        // ---- Daftar ulang → bayar → selesai → konversi -------------------
        $opened = $this->postJson("/api/v1/ppdb/registrations/{$registration}/re-registration/open", ['note' => 'Silakan daftar ulang.']);
        $this->refuse5xx($opened, 'POST /ppdb/registrations/{id}/re-registration/open');

        $reInvoice = (int) $opened->json('data.invoice.id');
        $this->assertGreaterThan(0, $reInvoice, 'Membuka daftar ulang menyiapkan tagihannya.');

        // Daftar ulang belum lunas → 422 (dijalankan sebagai pemegang hak
        // konversi supaya yang teruji adalah penjaga statusnya, bukan 403).
        $this->refuse5xx($this->as($this->panitia)->postJson("/api/v1/ppdb/registrations/{$registration}/re-registration/complete"), 'POST /ppdb/registrations/{id}/re-registration/complete (belum lunas)');
        $belum = $this->as($this->admin)->postJson("/api/v1/ppdb/registrations/{$registration}/convert-to-student");
        $this->refuse5xx($belum, 'POST /ppdb/registrations/{id}/convert-to-student (belum selesai)');
        $belum->assertStatus(422);

        // Nominalnya dibaca dari tagihan, bukan diasumsikan: daftar ulang bisa
        // saja menampung lebih dari satu baris biaya.
        $this->pay($parent, $reInvoice, (float) $opened->json('data.invoice.total_amount'));

        // `pay()` berakhir sebagai bendahara; menyelesaikan daftar ulang adalah hak
        // petugas pendaftaran, jadi pelakunya dipasang ulang.
        $completed = $this->as($this->panitia)
            ->postJson("/api/v1/ppdb/registrations/{$registration}/re-registration/complete", ['note' => 'Daftar ulang selesai.']);
        $this->refuse5xx($completed, 'POST /ppdb/registrations/{id}/re-registration/complete');
        $completed->assertOk();

        $converted = $this->as($this->admin)->postJson("/api/v1/ppdb/registrations/{$registration}/convert-to-student", ['nis' => '900999']);
        $this->refuse5xx($converted, 'POST /ppdb/registrations/{id}/convert-to-student');
        $this->assertTrue(
            (bool) $converted->json('data.created'),
            'Konversi membuat baris siswa baru. '.$converted->getContent()
        );

        // Konversi ulang (peserta idempoten) + halaman portal pasca-konversi.
        $this->refuse5xx($this->postJson("/api/v1/ppdb/registrations/{$registration}/convert-to-student"), 'POST /ppdb/registrations/{id}/convert-to-student (ulang)');
        $this->refuse5xx($this->as($parent['user'])->getJson("/api/v1/ppdb/portal/registrations/{$registration}/selection"), 'GET /ppdb/portal/registrations/{id}/selection (sesudah hasil)');

        // ---- Sanggah: admin membaca lalu memutus -------------------------
        $this->as($this->panitia);
        $this->refuse5xx($this->getJson('/api/v1/ppdb/appeals'), 'GET /ppdb/appeals');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/appeals?period_id='.$this->period->id.'&status=pending'), 'GET /ppdb/appeals (terfilter)');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/appeals/999999'), 'GET /ppdb/appeals/{id} (404)');
    }

    public function test_endpoint_sanggah_dapat_diproses_sampai_keputusan(): void
    {
        $parent = $this->registerParent('sanggah-smoke@sapuan.test', '081300000116');
        $registration = $this->verifiedRegistration($parent, $this->addApplicant($parent, 'Anak Sanggah', $this->female, '3301010101010213', '0123000213'));

        $this->as($this->admin)
            ->postJson("/api/v1/ppdb/registrations/{$registration}/result", ['result' => PpdbRegistration::STATUS_FAILED])
            ->assertOk();

        $sent = $this->as($parent['user'])->postJson("/api/v1/ppdb/portal/registrations/{$registration}/appeals", [
            'reason' => 'Nilai tes akademik saya berbeda dengan yang ditampilkan panitia.',
            'attachment' => UploadedFile::fake()->create('nilai.pdf', 100, 'application/pdf'),
        ]);
        $this->refuse5xx($sent, 'POST /ppdb/portal/registrations/{id}/appeals (berlampiran)');

        $appealId = (int) $sent->json('data.id');

        $this->as($this->panitia);
        $this->refuse5xx($this->getJson("/api/v1/ppdb/appeals/{$appealId}"), 'GET /ppdb/appeals/{id}');
        $this->refuse5xx($this->postJson("/api/v1/ppdb/appeals/{$appealId}/decide", ['decision' => 'accepted']), 'POST /ppdb/appeals/{id}/decide (tanpa catatan)');
        $this->refuse5xx($this->postJson("/api/v1/ppdb/appeals/{$appealId}/decide", [
            'decision' => 'accepted', 'review_note' => 'Berkas nilai ditinjau ulang.',
        ]), 'POST /ppdb/appeals/{id}/decide');
    }

    // =================================================================
    // ADMIN: DASHBOARD, LAPORAN, EKSPOR
    // =================================================================

    public function test_endpoint_dashboard_laporan_dan_ekspor_tidak_membalas_5xx(): void
    {
        $parent = $this->registerParent('lapor@sapuan.test', '081300000117');
        $registration = $this->verifiedRegistration($parent, $this->addApplicant($parent, 'Anak Lapor', $this->male, '3301010101010214', '0123000214'));
        $this->pay($parent, $this->invoiceOf($parent, $registration), 150000);

        $this->as($this->panitia);

        $this->refuse5xx($this->getJson('/api/v1/ppdb/dashboard'), 'GET /ppdb/dashboard');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/dashboard?period_id='.$this->period->id), 'GET /ppdb/dashboard (per periode)');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/dashboard?period_id=all&granularity=weekly&date_basis=submitted'), 'GET /ppdb/dashboard (semua periode)');

        // Opsi dropdown petugas (`ppdb.view`), dan penolakannya untuk orang tua.
        $this->refuse5xx($this->getJson('/api/v1/ppdb/lookups'), 'GET /ppdb/lookups');
        $this->refuse5xx($this->as($parent['user'])->getJson('/api/v1/ppdb/lookups'), 'GET /ppdb/lookups (orang tua)');
        $this->as($this->panitia);

        foreach (PpdbReportService::REPORT_TYPES as $type) {
            $this->refuse5xx($this->getJson("/api/v1/ppdb/reports/{$type}?period_id=".$this->period->id), "GET /ppdb/reports/{$type}");
        }

        // Kata kunci + filter status: jalur `applyKeyword()` juga dipakai laporan.
        $this->refuse5xx(
            $this->getJson('/api/v1/ppdb/reports/registration?period_id='.$this->period->id.'&keyword=Anak&status=submitted'),
            'GET /ppdb/reports/registration (kata kunci)',
        );

        $this->refuse5xx($this->getJson('/api/v1/ppdb/reports/bukan-jenis'), 'GET /ppdb/reports/{type} (404)');

        foreach (PpdbReportService::EXPORT_TYPES as $type) {
            $csv = $this->getJson("/api/v1/ppdb/export?type={$type}");
            $this->refuse5xx($csv, "GET /ppdb/export?type={$type}");
            $csv->assertOk();
        }

        $this->refuse5xx($this->getJson('/api/v1/ppdb/export?type=registration&period_id=all&keyword=Anak'), 'GET /ppdb/export (terfilter)');
        $this->refuse5xx($this->getJson('/api/v1/ppdb/export'), 'GET /ppdb/export (tanpa type)');

        foreach (PpdbReportService::REPORT_TYPES as $type) {
            $pdf = $this->getJson("/api/v1/ppdb/reports/{$type}/pdf");
            $this->refuse5xx($pdf, "GET /ppdb/reports/{$type}/pdf");
            $pdf->assertOk();
        }

        // Otorisasi: orang tua tanpa permission modul tetap non-5xx.
        $this->refuse5xx($this->as($parent['user'])->getJson('/api/v1/ppdb/dashboard'), 'GET /ppdb/dashboard (orang tua)');
        $this->refuse5xx($this->as($parent['user'])->getJson('/api/v1/ppdb/reports/registration'), 'GET /ppdb/reports/registration (orang tua)');
        $this->refuse5xx($this->as($this->bendahara)->getJson('/api/v1/ppdb/export?type=registration'), 'GET /ppdb/export (bendahara tanpa hak ekspor)');
    }

    // =================================================================
    // HELPER
    // =================================================================

    /**
     * Inti sapuan: endpoint mana pun boleh menjawab apa pun (200/201/403/422/404),
     * asalkan BUKAN 5xx. Pesan 5xx ikut ditampilkan supaya penyebabnya terbaca.
     */
    private function refuse5xx(TestResponse $response, string $endpoint): void
    {
        // `status()` hanya ada pada respons JSON Laravel, sedangkan unduhan CSV
        // memakai StreamedResponse — `getStatusCode()` sah untuk keduanya.
        $status = $response->getStatusCode();

        $this->assertLessThan(
            500,
            $status,
            "Endpoint {$endpoint} membalas {$status}:\n".$response->getContent()
        );
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->syncRoles([$role]);

        return $user;
    }

    /** `as()` menerima User atau hasil `registerParent()` (array user+token). */
    private function as(User|array $actor): static
    {
        Sanctum::actingAs($actor instanceof User ? $actor : $actor['user'], ['*']);

        return $this;
    }

    /** @return array{user: User, token: string} */
    private function registerParent(string $email, string $phone): array
    {
        $created = $this->postJson('/api/v1/ppdb/auth/register', [
            'name' => 'Wali '.substr(md5($email), 0, 6),
            'email' => $email,
            'phone' => $phone,
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'address' => 'Jl. Sapuan Asap No. 1, Bandung',
        ])->assertCreated();

        return [
            'user' => User::query()->firstWhere('email', $email),
            'token' => (string) $created->json('data.token'),
        ];
    }

    private function addApplicant(array $parent, string $name, Gender $gender, string $nik, string $nisn): int
    {
        return (int) $this->as($parent['user'])
            ->postJson('/api/v1/ppdb/portal/applicants', [
                'full_name' => $name,
                'nickname' => substr($name, 0, 6),
                'gender_id' => $gender->id,
                'birth_place' => 'Bandung',
                'birth_date' => '2010-04-01',
                'nik' => $nik,
                'nisn' => $nisn,
                'previous_school' => 'SMP Sapuan',
                'address' => 'Jl. Sapuan Asap No. 1',
                'target_program_id' => $this->program->id,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    private function createRegistration(array $parent, int $applicantId): int
    {
        return (int) $this->as($parent['user'])
            ->postJson('/api/v1/ppdb/portal/registrations', [
                'applicant_id' => $applicantId,
                'period_id' => $this->period->id,
                'program_id' => $this->program->id,
                'wave_id' => $this->wave->id,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    private function uploadDocument(array $parent, int $registrationId): void
    {
        $this->as($parent['user'])
            ->postJson("/api/v1/ppdb/portal/registrations/{$registrationId}/documents", [
                'requirement_id' => $this->requirement->id,
                'file' => UploadedFile::fake()->create('kk.pdf', 120, 'application/pdf'),
            ])
            ->assertSuccessful();
    }

    /** Draft → submitted (berkas terunggah), semuanya lewat HTTP. */
    private function submittedRegistration(array $parent, int $applicantId): int
    {
        $registration = $this->createRegistration($parent, $applicantId);

        $this->uploadDocument($parent, $registration);

        $this->as($parent['user'])
            ->postJson("/api/v1/ppdb/portal/registrations/{$registration}/submit")
            ->assertOk();

        return $registration;
    }

    /** submitted → under_review → berkas disetujui → verified, lewat HTTP. */
    private function verifiedRegistration(array $parent, int $applicantId): int
    {
        $registration = $this->submittedRegistration($parent, $applicantId);

        $this->as($this->panitia);

        $this->postJson("/api/v1/ppdb/registrations/{$registration}/transition", [
            'status' => PpdbRegistration::STATUS_UNDER_REVIEW,
            'note' => 'Berkas sedang diteliti panitia.',
        ])->assertOk();

        foreach ($this->documentsOf($registration) as $document) {
            $this->postJson("/api/v1/ppdb/documents/{$document['id']}/review", [
                'status' => PpdbApplicantDocument::STATUS_APPROVED,
            ])->assertOk();
        }

        $this->postJson("/api/v1/ppdb/registrations/{$registration}/verify", ['note' => 'Lengkap.'])->assertOk();

        return $registration;
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function documentsOf(int $registrationId): \Illuminate\Support\Collection
    {
        $this->as($this->panitia);

        $rows = (array) $this->getJson('/api/v1/ppdb/documents?registration_id='.$registrationId)
            ->assertOk()
            ->json('data');

        return collect($rows)->filter(
            static fn (array $row): bool => ! isset($row['registration_id']) || (int) $row['registration_id'] === $registrationId
        )->values();
    }

    /** ID tagihan (jenis default) satu pendaftaran, dibaca orang tuanya. */
    private function invoiceOf(array $parent, int $registrationId, string $type = PpdbInvoice::TYPE_REGISTRATION): int
    {
        $invoices = (array) $this->as($parent['user'])
            ->getJson("/api/v1/ppdb/portal/registrations/{$registrationId}/payment")
            ->assertOk()
            ->json('data.invoices');

        $invoice = collect($invoices)->firstWhere('type', $type);

        $this->assertNotNull($invoice, "Tagihan {$type} tersedia di portal orang tua.");

        return (int) $invoice['id'];
    }

    /** Ajukan pembayaran manual (belum diverifikasi) → kembalikan id pembayaran. */
    private function payManual(array $parent, int $registrationId, float $amount): int
    {
        $invoiceId = $this->invoiceOf($parent, $registrationId);

        return (int) $this->as($parent['user'])
            ->postJson("/api/v1/ppdb/portal/invoices/{$invoiceId}/pay", [
                'amount' => $amount,
                'method' => 'manual',
                'payment_method_id' => $this->transfer->id,
                'reference' => 'SMK-'.uniqid(),
                'proof' => UploadedFile::fake()->create('bukti.jpg', 90, 'image/jpeg'),
            ])
            ->assertSuccessful()
            ->json('data.payment.id');
    }

    /** Bayar lewat portal lalu verifikasi oleh bendahara. */
    private function pay(array $parent, int $invoiceId, float $amount): void
    {
        $payment = (int) $this->as($parent['user'])
            ->postJson("/api/v1/ppdb/portal/invoices/{$invoiceId}/pay", [
                'amount' => $amount,
                'method' => 'manual',
                'payment_method_id' => $this->transfer->id,
                'reference' => 'SMK-'.uniqid(),
                'proof' => UploadedFile::fake()->create('bukti.jpg', 90, 'image/jpeg'),
            ])
            ->assertSuccessful()
            ->json('data.payment.id');

        $this->as($this->bendahara)
            ->postJson("/api/v1/ppdb/payments/{$payment}/verify", ['note' => 'Uang sudah masuk rekening.'])
            ->assertOk();
    }
}
