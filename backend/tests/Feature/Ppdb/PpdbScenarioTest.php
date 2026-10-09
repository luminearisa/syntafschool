<?php

namespace Tests\Feature\Ppdb;

use App\Models\Gender;
use App\Models\Guardian;
use App\Models\PpdbAnnouncement;
use App\Models\PpdbApplicantDocument;
use App\Models\PpdbDocumentRequirement;
use App\Models\PpdbInvoice;
use App\Models\PpdbParentAccount;
use App\Models\PpdbPayment;
use App\Models\PpdbRegistrationFee;
use App\Models\PpdbPeriod;
use App\Models\PpdbProgram;
use App\Models\PpdbRegistration;
use App\Models\PpdbRegistrationAppeal;
use App\Models\PpdbSelectionParticipant;
use App\Models\PpdbSelectionStage;
use App\Models\PpdbWave;
use App\Models\PaymentMethod;
use App\Models\Student;
use App\Models\User;
use App\Notifications\PpdbActivityNotification;
use App\Services\Ppdb\PpdbMidtransGateway;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Matriks skenario A–G PPDB (audit §42) — tiap metode = satu skenario wajib,
 * dijalankan sepenuhnya lewat HTTP.
 *
 * Aturan main (sama seperti PpdbWorkflowTest):
 * - state pindah HANYA lewat endpoint; tidak ada `forceFill`/update status di DB;
 * - master data (periode, gelombang, program, persyaratan dokumen, biaya, tahap)
 *   boleh dibuat langsung di `setUp` karena ia konfigurasi, bukan status;
 * - berkas diverifikasi benar-benar ada di disk `public`, bukan hanya barisnya;
 * - iris dengan PpdbWorkflowTest / PpdbPublicPortalTest / PpdbConfigTest /
 *   PpdbReportTest tidak diulang: file ini menguji RANTAI antar-modul.
 */
class PpdbScenarioTest extends TestCase
{
    use RefreshDatabase;

    private PpdbPeriod $period;

    private PpdbWave $wave;

    private PpdbProgram $program;

    private PpdbProgram $tight;

    private PpdbDocumentRequirement $requirement;

    private Gender $male;

    private Gender $female;

    private PaymentMethod $transfer;

    private User $panitia;

    private User $bendahara;

    private User $kepala;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->seed(RolePermissionSeeder::class);

        $this->male = Gender::query()->create(['code' => 'M', 'name' => 'Laki-laki']);
        $this->female = Gender::query()->create(['code' => 'F', 'name' => 'Perempuan']);
        $this->transfer = PaymentMethod::query()->create(['code' => 'TRF', 'name' => 'Transfer Bank']);

        $this->period = PpdbPeriod::query()->create([
            'code' => 'PPDB-SKN', 'name' => 'PPDB Skenario Uji', 'slug' => 'ppdb-skenario-uji',
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
            'period_id' => $this->period->id, 'code' => 'GS-UJI', 'name' => 'Gelombang Skenario',
            'quota' => 20, 'priority' => 1,
            'registration_start' => now()->subMonth()->toDateString(),
            'registration_end' => now()->addMonth()->toDateString(),
        ]);

        $this->program = PpdbProgram::query()->create([
            'period_id' => $this->period->id, 'code' => 'REG', 'name' => 'Program Reguler',
            'quota' => 5, 'sort_order' => 1,
        ]);

        $this->tight = PpdbProgram::query()->create([
            'period_id' => $this->period->id, 'code' => 'KETAT', 'name' => 'Program Kuota Satu',
            'quota' => 1, 'sort_order' => 2,
        ]);

        $this->requirement = PpdbDocumentRequirement::query()->create([
            'period_id' => $this->period->id, 'code' => 'KK', 'name' => 'Kartu Keluarga',
            'required' => true, 'active' => true, 'order' => 1,
            'file_types' => 'jpg,jpeg,png,pdf', 'max_size_kb' => 1024,
        ]);

        PpdbRegistrationFee::query()->create([
            'period_id' => $this->period->id, 'type' => PpdbRegistrationFee::TYPE_REGISTRATION,
            'name' => 'Biaya Pendaftaran', 'amount' => 150000, 'required' => true, 'active' => true,
        ]);
        PpdbRegistrationFee::query()->create([
            'period_id' => $this->period->id, 'type' => PpdbRegistrationFee::TYPE_OTHER,
            'name' => 'Biaya Daftar Ulang', 'amount' => 250000, 'required' => true, 'active' => true,
        ]);

        $this->panitia = $this->staff('panitia-ppdb');
        $this->bendahara = $this->staff('bendahara');
        $this->kepala = $this->staff('admin-sekolah');
    }

    // =================================================================
    // SKENARIO A — halaman publik tanpa token + orang tua daftar mandiri
    // =================================================================

    public function test_skenario_a_halaman_publik_tanpa_token_lalu_wali_daftar_dan_langsung_pakai_portal(): void
    {
        // 1. Tanpa header Authorization sama sekali: daftar + halaman periode terbuka.
        $this->assertGuest();

        $list = $this->getJson('/api/v1/ppdb/public')->assertOk();
        $this->assertContains($this->period->slug, array_column((array) $list->json('data'), 'slug'));

        $page = $this->getJson('/api/v1/ppdb/public/'.$this->period->slug)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.slug', $this->period->slug)
            ->assertJsonPath('data.status', PpdbPeriod::STATUS_OPEN)
            ->assertJsonPath('data.quota.registered', 0)
            ->json();

        $this->assertNotEmpty(data_get($page, 'data.programs'), 'Halaman publik menawarkan program.');
        $this->assertNotEmpty(data_get($page, 'data.waves'), 'Halaman publik menampilkan gelombang.');

        // 2. Gerbang portal tetap tertutup untuk tamu.
        $this->getJson('/api/v1/ppdb/portal/dashboard')->assertUnauthorized();
        $this->getJson('/api/v1/ppdb/portal/registrations')->assertUnauthorized();

        // 3. Daftar mandiri lewat HTTP — token asli dikembalikan.
        $email = 'wali.skenario-a@ppdb-skenario.test';

        $registered = $this->postJson('/api/v1/ppdb/auth/register', [
            'name' => 'Wali Skenario A',
            'email' => $email,
            'phone' => '081311000001',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'address' => 'Jl. Skenario A No. 1, Bandung',
        ])->assertCreated();

        $token = (string) $registered->json('data.token');
        $this->assertNotSame('', $token, 'Token Sanctum wajib dikembalikan agar wali langsung memakai portal.');
        $this->assertTrue($registered->json('data.can_transact'), 'Akun mandiri langsung aktif (can_transact).');
        $this->assertSame('wali-murid', $registered->json('data.user.roles.0'));
        $this->assertSame(PpdbParentAccount::STATUS_ACTIVE, $registered->json('data.account_status'));
        $this->assertTrue((bool) $registered->json('data.one_account_for_all_children'));

        $user = User::query()->firstWhere('email', $email);
        $this->assertNotNull($user, 'Akun mandiri menulis baris `users` nyata.');

        // 4. TOKEN ASLI dipakai (bukan Sanctum::actingAs) — dashboard, profil, registrasi.
        $this->withToken($token)
            ->getJson('/api/v1/ppdb/portal/dashboard')
            ->assertOk()
            ->assertJsonPath('data.parent.user_id', $user->id)
            ->assertJsonPath('data.registration_count', 0)
            ->assertJsonPath('data.applicant_count', 0);

        $this->withToken($token)
            ->getJson('/api/v1/ppdb/portal/profile')
            ->assertOk()
            ->assertJsonPath('data.user.email', $email)
            ->assertJsonPath('data.parent.account_status', 'active')
            ->assertJsonPath('data.children_count', 0);

        // 5. Profil langsung bisa diisi pada sesi yang sama.
        $this->withToken($token)
            ->putJson('/api/v1/ppdb/portal/profile', [
                'name' => 'Wali Skenario A Baru',
                'phone' => '081311000009',
                'address' => 'Jl. Skenario A No. 9, Bandung',
                'gender_id' => $this->female->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.parent.name', 'Wali Skenario A Baru')
            ->assertJsonPath('data.parent.address', 'Jl. Skenario A No. 9, Bandung');

        // 6. Anak + pendaftaran pertama lewat portal, masih dengan token yang sama.
        $applicantId = $this->addApplicant($user, 'Anak Skenario A', $this->female, '3301010101010201', '0123000201');
        $registration = $this->createRegistration($user, $applicantId);

        $this->withToken($token)
            ->getJson('/api/v1/ppdb/portal/dashboard')
            ->assertOk()
            ->assertJsonPath('data.registration_count', 1)
            ->assertJsonPath('data.applications.0.status', PpdbRegistration::STATUS_DRAFT);

        // 7. Halaman publik tetap terbuka setelah wali login.
        $this->withToken($token)
            ->getJson('/api/v1/ppdb/public/'.$this->period->slug)
            ->assertOk()
            ->assertJsonPath('data.quota.registered', 1);
    }

    // =================================================================
    // SKENARIO B — satu wali, dua anak: satu baris users, dua pendaftaran mandiri
    // =================================================================

    public function test_skenario_b_satu_wali_mendaftarkan_dua_anak_tanpa_menambah_user(): void
    {
        $parent = $this->registerParent('wali.duaanak@ppdb-skenario.test', '081311000002');
        $user = $parent['user'];

        $usersBefore = User::query()->count();

        $firstApplicant = $this->addApplicant($user, 'Anak Sulung', $this->male, '3301010101010211', '0123000211');
        $secondApplicant = $this->addApplicant($user, 'Anak Bungsu', $this->female, '3301010101010212', '0123000212');

        // Menambah anak TIDAK membuat user/account baru.
        $this->assertSame($usersBefore, User::query()->count(), 'Calon kedua tidak boleh menambah baris `users`.');

        $first = $this->createRegistration($user, $firstApplicant);
        $second = $this->createRegistration($user, $secondApplicant, $this->tight);

        $this->assertSame($usersBefore, User::query()->count(), 'Pendaftaran kedua tidak boleh menambah baris `users`.');
        $this->assertDatabaseCount('ppdb_parent_accounts', 1);
        $this->assertSame(2, (int) $this->as($user)->getJson('/api/v1/ppdb/portal/profile')->assertOk()->json('data.children_count'));

        // Dua pendaftaran berbeda, nomor sendiri-sendiri.
        $this->assertNotSame($first, $second);
        $index = $this->as($user)->getJson('/api/v1/ppdb/portal/registrations')->assertOk()->json('data');
        $this->assertCount(2, $index);
        $this->assertNotSame(
            collect((array) $index)->firstWhere('id', $first)['registration_number'],
            collect((array) $index)->firstWhere('id', $second)['registration_number'],
        );

        // Dua anak terpisah pada tabel calon + keduanya menaut wali yang sama.
        $applicants = $this->as($user)->getJson('/api/v1/ppdb/portal/applicants')->assertOk()->json('data');
        $this->assertCount(2, $applicants);

        // Kemandirian alur: anak sulung dikirim, anak bungsu tetap draft.
        $this->uploadDocument($user, $first);
        $this->as($user)->postJson("/api/v1/ppdb/portal/registrations/{$first}/submit")->assertOk();

        $this->assertSame(PpdbRegistration::STATUS_SUBMITTED, $this->registrationRow($first)['status']);
        $this->assertSame(PpdbRegistration::STATUS_DRAFT, $this->registrationRow($second)['status']);

        // Bungsu masih boleh diedit (draft) sementara sulung sudah dibekukan (submitted).
        $this->as($user)->putJson("/api/v1/ppdb/portal/registrations/{$second}", [
            'applicant' => ['nickname' => 'Bungsu Edit'],
        ])->assertOk();

        $this->as($user)->putJson("/api/v1/ppdb/portal/registrations/{$first}", [
            'applicant' => ['nickname' => 'Sulung Edit'],
        ])->assertStatus(422);

        // Dua pendaftaran dihitung terpisah pada kuota publik.
        $public = $this->getJson('/api/v1/ppdb/public/'.$this->period->slug)->assertOk();
        $this->assertSame(2, $public->json('data.quota.registered'));
        $this->assertSame(1, collect($public->json('data.programs'))->firstWhere('code', 'REG')['registered']);
        $this->assertSame(1, collect($public->json('data.programs'))->firstWhere('code', 'KETAT')['registered']);

        // Total user tetap: 3 petugas + 1 wali.
        $this->assertSame(4, User::query()->count());
    }

    // =================================================================
    // SKENARIO C — formulir dinamis + dokumen wajib + versi berkas
    // =================================================================

    public function test_skenario_c_formulir_dinamis_dan_dokumen_berversi_tidak_tertimpa(): void
    {
        // 1. Panitia membuat field + pertanyaan + persyaratan dokumen baru lewat konfigurasi.
        $this->as($this->panitia);

        $fieldId = (int) $this->postJson('/api/v1/ppdb/form-fields', [
            'period_id' => $this->period->id,
            'field_key' => 'asal_sekolah',
            'label' => 'Nama Sekolah Asal',
            'type' => 'select',
            'section' => 'sekolah_asal',
            'required' => true,
            'order' => 1,
            'options' => ['SMPN1', 'SMPN2'],
            'validation' => ['max:150'],
        ])->assertCreated()->json('data.id');

        $questionId = (int) $this->postJson('/api/v1/ppdb/questions', [
            'period_id' => $this->period->id,
            'code' => 'ASAL-TAHDIR',
            'question' => 'Apakah calon siap masuk asrama tahfidz?',
            'type' => 'single_choice',
            'required' => true,
            'order' => 1,
            'options' => [['label' => 'Ya', 'value' => 'ya'], ['label' => 'Tidak', 'value' => 'tidak']],
        ])->assertCreated()->json('data.id');

        $secondRequirement = (int) $this->postJson('/api/v1/ppdb/document-requirements', [
            'period_id' => $this->period->id,
            'code' => 'IJAZAH',
            'name' => 'Ijazah/SKL SMP',
            'required' => true,
            'active' => true,
            'order' => 2,
            'file_types' => 'jpg,pdf',
            'max_size_kb' => 1024,
        ])->assertCreated()->json('data.id');

        $this->assertGreaterThan(0, $fieldId);
        $this->assertGreaterThan(0, $questionId);

        $requirementId = (int) $this->requirement->id;

        // 2. Semua konfigurasi di atas muncul pada skema portal.
        $parent = $this->registerParent('wali.form@ppdb-skenario.test', '081311000003');
        $registration = $this->createRegistration($parent, $this->addApplicant($parent, 'Calan Form', $this->male, '3301010101010221', '0123000221'));

        $detail = $this->as($parent['user'])
            ->getJson("/api/v1/ppdb/portal/registrations/{$registration}")
            ->assertOk()
            ->assertJsonPath('data.editable', true)
            ->json();

        $this->assertNotNull(
            collect((array) data_get($detail, 'data.schema.fields'))->firstWhere('key', 'asal_sekolah'),
            'Field buatan admin harus muncul pada skema portal.',
        );
        $this->assertSame(
            'Apakah calon siap masuk asrama tahfidz?',
            collect((array) data_get($detail, 'data.schema.questions'))->firstWhere('id', $questionId)['question'] ?? null,
            'Pertanyaan buatan admin harus muncul pada skema portal.',
        );
        $this->assertNotNull(
            collect((array) data_get($detail, 'data.schema.documents'))->firstWhere('id', $secondRequirement),
            'Persyaratan dokumen buatan admin harus muncul pada skema portal.',
        );
        $this->assertFalse(data_get($detail, 'data.can_submit'));
        $this->assertGreaterThan(0, (int) data_get($detail, 'data.blockers.total'));

        // 3. Kirim ditolak: field wajib, pertanyaan wajib, dan DUA dokumen wajib.
        $blocked = $this->as($parent['user'])
            ->postJson("/api/v1/ppdb/portal/registrations/{$registration}/submit")
            ->assertStatus(422);

        $blocked->assertJsonValidationErrors(['fields.asal_sekolah', 'questions.'.$questionId, 'documents']);

        $this->assertNotEmpty($blocked->json('errors.documents'), 'Dokumen wajib yang belum ada harus menghambat submit.');

        // 4. Jawaban diisi → dokumen masih menghambat.
        $this->as($parent['user'])
            ->putJson("/api/v1/ppdb/portal/registrations/{$registration}", [
                'fields' => ['asal_sekolah' => 'SMPN1'],
                'questions' => [(string) $questionId => 'ya'],
            ])
            ->assertOk()
            ->assertJsonPath('data.blockers.fields', [])
            ->assertJsonPath('data.blockers.questions', []);

        $stillBlocked = $this->as($parent['user'])
            ->postJson("/api/v1/ppdb/portal/registrations/{$registration}/submit")
            ->assertStatus(422);

        $this->assertArrayNotHasKey('fields.asal_sekolah', (array) $stillBlocked->json('errors'));
        $this->assertNotEmpty($stillBlocked->json('errors.documents'));

        // 5. Dua dokumen diunggah → berkas benar-benar ada di disk public.
        $kk = $this->as($parent['user'])
            ->postJson("/api/v1/ppdb/portal/registrations/{$registration}/documents", [
                'requirement_id' => $this->requirement->id,
                'file' => UploadedFile::fake()->create('kk.pdf', 120, 'application/pdf'),
            ])
            ->assertCreated()
            ->json();

        $ijazah = $this->as($parent['user'])
            ->postJson("/api/v1/ppdb/portal/registrations/{$registration}/documents", [
                'requirement_id' => $secondRequirement,
                'file' => UploadedFile::fake()->create('ijazah.jpg', 90, 'image/jpeg'),
            ])
            ->assertCreated()
            ->json();

        $kkPath = (string) data_get($kk, 'data.document.file_path');
        $ijazahPath = (string) data_get($ijazah, 'data.document.file_path');

        $this->assertTrue(Storage::disk('public')->exists($kkPath), "Berkas KK harus benar-benar tertulis: {$kkPath}");
        $this->assertTrue(Storage::disk('public')->exists($ijazahPath), "Berkas Ijazah harus benar-benar tertulis: {$ijazahPath}");
        $this->assertSame(1, (int) data_get($kk, 'data.document.version'));
        $this->assertTrue((bool) data_get($kk, 'data.document.is_active'));

        // 6. Setelah dokumen lengkap, submit boleh.
        $this->as($parent['user'])
            ->postJson("/api/v1/ppdb/portal/registrations/{$registration}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', PpdbRegistration::STATUS_SUBMITTED);

        // 7. Panitia MENOLAK KK (alasan wajib) → orang tua melihat alasannya.
        $kkDocumentId = (int) data_get($kk, 'data.document.id');

        $this->as($this->panitia)
            ->postJson("/api/v1/ppdb/documents/{$kkDocumentId}/review", ['status' => PpdbApplicantDocument::STATUS_REJECTED])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $rejected = $this->as($this->panitia)
            ->postJson("/api/v1/ppdb/documents/{$kkDocumentId}/review", [
                'status' => PpdbApplicantDocument::STATUS_REJECTED,
                'note' => 'Scan KK terpotong, data NIK tidak terbaca.',
            ])
            ->assertOk();

        $this->assertSame(PpdbApplicantDocument::STATUS_REJECTED, $rejected->json('data.document.status'));

        $portalDocuments = (array) $this->as($parent['user'])
            ->getJson("/api/v1/ppdb/portal/registrations/{$registration}/documents")
            ->assertOk()
            ->json('data.requirements');

        $kkSlot = collect($portalDocuments)->firstWhere('code', 'KK');

        $this->assertNotNull($kkSlot);
        $this->assertSame(
            'Scan KK terpotong, data NIK tidak terbaca.',
            data_get($kkSlot, 'versions.0.review_note'),
            'Catatan penolakan harus terbaca oleh orang tua.',
        );

        // Notifikasi penolakan dokumen mendarat di wali.
        $types = array_column((array) $this->as($parent['user'])
            ->getJson('/api/v1/ppdb/portal/notifications?per_page=100')->assertOk()->json('data'), 'type');
        $this->assertContains(PpdbActivityNotification::TYPE_DOCUMENT_REJECTED, $types);

        // 8. Unggah ulang → versi 2; versi 1 TIDAK tertimpa dan masih ada di disk.
        $v2 = $this->as($parent['user'])
            ->postJson("/api/v1/ppdb/portal/registrations/{$registration}/documents", [
                'requirement_id' => $this->requirement->id,
                'file' => UploadedFile::fake()->create('kk-baru.pdf', 140, 'application/pdf'),
            ])
            ->assertCreated()
            ->json();

        $this->assertSame(2, (int) data_get($v2, 'data.document.version'));
        $this->assertCount(1, (array) data_get($v2, 'data.previous_versions'));
        $this->assertSame($kkDocumentId, (int) data_get($v2, 'data.previous_versions.0.id'));

        $v2Path = (string) data_get($v2, 'data.document.file_path');
        $this->assertNotSame($kkPath, $v2Path, 'Versi baru memakai jalur berkas sendiri.');
        $this->assertTrue(Storage::disk('public')->exists($v2Path));
        $this->assertTrue(Storage::disk('public')->exists($kkPath), 'Berkas versi 1 harus tetap ada (riwayat tidak ditimpa).');

        // 9. Panel panitia: bawaan hanya versi aktif, `all_versions=1` menampilkan dua versi.
        $this->as($this->panitia);

        $activeOnly = collect((array) $this->getJson('/api/v1/ppdb/documents?registration_id='.$registration)
            ->assertOk()
            ->json('data'));

        $this->assertCount(2, $activeOnly, 'Daftar bawaan hanya versi aktif (KK v2 + Ijazah v1).');
        $this->assertSame(1, $activeOnly->where('version', 2)->count());
        $this->assertTrue($activeOnly->every(static fn (array $row): bool => (bool) $row['is_active']));

        $allVersions = (array) $this->getJson('/api/v1/ppdb/documents?registration_id='.$registration.'&all_versions=1')
            ->assertOk()
            ->json('data');

        $kkVersions = collect($allVersions)->filter(
            static fn (array $row): bool => (int) $row['requirement_id'] === $requirementId
        );

        $this->assertCount(2, $kkVersions);
        $this->assertSame([2, 1], $kkVersions->sortByDesc('version')->values()->map(static fn (array $row): int => (int) $row['version'])->all());
        $this->assertSame(1, $kkVersions->filter(static fn (array $row): bool => (bool) $row['is_active'])->count(), 'Hanya satu versi aktif.');

        // 10. Versi lama tidak boleh ditinjau lagi (penjaga versi).
        $this->postJson("/api/v1/ppdb/documents/{$kkDocumentId}/review", [
            'status' => PpdbApplicantDocument::STATUS_APPROVED,
        ])->assertStatus(422)->assertJsonValidationErrors('document');

        // 11. Versi aktif baru disetujui → kelengkapan dokumen pulih.
        $this->postJson("/api/v1/ppdb/documents/".(int) data_get($v2, 'data.document.id').'/review', [
            'status' => PpdbApplicantDocument::STATUS_APPROVED,
        ])->assertOk();

        $this->postJson("/api/v1/ppdb/documents/".(int) data_get($ijazah, 'data.document.id').'/review', [
            'status' => PpdbApplicantDocument::STATUS_APPROVED,
        ])->assertOk();

        $this->assertSame(PpdbRegistration::DOCUMENT_APPROVED, $this->registrationRow($registration)['document_status']);
    }

    // =================================================================
    // SKENARIO D — verifikasi bertahap + jalur need_revision
    // =================================================================

    public function test_skenario_d_verifikasi_bertahap_dan_detour_need_revision_kembali_ke_submitted(): void
    {
        $parent = $this->registerParent('wali.bertahap@ppdb-skenario.test', '081311000004');
        $registration = $this->createRegistration($parent, $this->addApplicant($parent, 'Calan Bertahap', $this->female, '3301010101010231', '0123000231'));

        $this->uploadDocument($parent, $registration);
        $this->as($parent['user'])->postJson("/api/v1/ppdb/portal/registrations/{$registration}/submit")->assertOk();

        $this->assertSame(PpdbRegistration::STATUS_SUBMITTED, $this->registrationRow($registration)['status']);

        // 1. Mesin status melompat-lompat ditolak: submitted tidak boleh langsung verified.
        $skipped = $this->as($this->panitia)
            ->postJson("/api/v1/ppdb/registrations/{$registration}/verify", ['note' => 'Langsung verifikasi.'])
            ->assertStatus(422);

        $this->assertStringContainsString('harus bertahap', (string) $skipped->json('errors.status.0'));

        $this->postJson("/api/v1/ppdb/registrations/{$registration}/transition", [
            'status' => PpdbRegistration::STATUS_VERIFIED,
            'note' => 'Lompat tahap dilarang.',
        ])->assertStatus(422)->assertJsonValidationErrors('status');

        // 2. submitted → under_review.
        $this->postJson("/api/v1/ppdb/registrations/{$registration}/transition", [
            'status' => PpdbRegistration::STATUS_UNDER_REVIEW,
            'note' => 'Berkas sedang diteliti panitia.',
        ])->assertOk()->assertJsonPath('data.status', PpdbRegistration::STATUS_UNDER_REVIEW);

        // 3. Dokumen ditinjau panitia.
        $document = $this->documentsOf($registration)->first();

        $this->postJson("/api/v1/ppdb/documents/{$document['id']}/review", [
            'status' => PpdbApplicantDocument::STATUS_UNDER_REVIEW,
        ])->assertOk()->assertJsonPath('data.document.status', PpdbApplicantDocument::STATUS_UNDER_REVIEW);

        $this->assertSame(PpdbRegistration::DOCUMENT_UNDER_REVIEW, $this->registrationRow($registration)['document_status']);

        // 4. under_review → need_revision (detour), dengan rincian butir yang harus diperbaiki.
        $revision = $this->postJson("/api/v1/ppdb/registrations/{$registration}/request-revision", [
            'note' => 'Tanggal lahir pada biodata perlu disesuaikan dengan KK.',
            'targets' => [[
                'type' => 'document',
                'id' => $this->requirement->id,
                'reason' => 'Kartu keluarga belum memuat seluruh anggota keluarga.',
            ]],
        ])->assertOk();

        $revision->assertJsonPath('data.status', PpdbRegistration::STATUS_NEED_REVISION);
        $this->assertStringContainsString(
            'Kartu keluarga belum memuat',
            (string) $this->registrationRow($registration)['revision_note'],
            'Rincian butir revisi ikut terbaca pada catatan.',
        );

        // need_revision membuat berkas boleh diedit + diunggah ulang oleh wali.
        $this->as($parent['user'])
            ->putJson("/api/v1/ppdb/portal/registrations/{$registration}", [
                'applicant' => ['birth_date' => '2010-05-06'],
            ])
            ->assertOk()
            ->assertJsonPath('data.editable', true);

        $this->uploadDocument($parent, $registration);

        // 5. need_revision → submitted (dikirim ulang oleh wali), lalu bertahap lagi.
        $this->as($parent['user'])
            ->postJson("/api/v1/ppdb/portal/registrations/{$registration}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', PpdbRegistration::STATUS_SUBMITTED);

        $this->as($this->panitia)
            ->postJson("/api/v1/ppdb/registrations/{$registration}/transition", [
                'status' => PpdbRegistration::STATUS_UNDER_REVIEW,
                'note' => 'Penelitian ulang setelah revisi.',
            ])->assertOk();

        foreach ($this->documentsOf($registration) as $row) {
            $this->postJson("/api/v1/ppdb/documents/{$row['id']}/review", [
                'status' => PpdbApplicantDocument::STATUS_APPROVED,
            ])->assertOk();
        }

        $this->postJson("/api/v1/ppdb/registrations/{$registration}/verify", ['note' => 'Berkas sesuai.'])
            ->assertOk()
            ->assertJsonPath('data.status', PpdbRegistration::STATUS_VERIFIED);

        // 6. Riwayat mencatat seluruh etape secara berurutan.
        $actions = array_column($this->as($this->kepala)->getJson("/api/v1/ppdb/registrations/{$registration}/history")->assertOk()->json('data'), 'action');
        $joined = implode(',', $actions);

        foreach ([
            'status.submitted',
            'status.under_review',
            'revision.requested',
            'status.need_revision',
            'status.verified',
        ] as $expected) {
            $this->assertStringContainsString($expected, $joined, "Riwayat kehilangan etape {$expected}.");
        }

        // 7. Notifikasi tahap verifikasi diterima wali.
        $types = array_column((array) $this->as($parent['user'])
            ->getJson('/api/v1/ppdb/portal/notifications?per_page=100')->assertOk()->json('data'), 'type');

        $this->assertContains(PpdbActivityNotification::TYPE_REVISION_REQUESTED, $types);
        $this->assertContains(PpdbActivityNotification::TYPE_REGISTRATION_VERIFIED, $types);
    }

    // =================================================================
    // SKENARIO E — pembayaran manual, verifikasi bendahara, Midtrans
    // =================================================================

    public function test_skenario_e_pembayaran_manual_bertahap_penolakan_dan_jalur_midtrans(): void
    {
        config(['services.midtrans.server_key' => null]);

        $parent = $this->registerParent('wali.bayar@ppdb-skenario.test', '081311000005');
        $registration = $this->verifiedRegistration($parent, $this->addApplicant($parent, 'Calan Bayar', $this->male, '3301010101010241', '0123000241'));

        // 1. Halaman pembayaran membuka tagihan biaya pendaftaran.
        $page = $this->as($parent['user'])
            ->getJson("/api/v1/ppdb/portal/registrations/{$registration}/payment")
            ->assertOk()
            ->json();

        $invoice = collect((array) data_get($page, 'data.invoices'))->firstWhere('type', PpdbInvoice::TYPE_REGISTRATION);
        $this->assertNotNull($invoice);
        $invoiceId = (int) $invoice['id'];

        $this->assertSame(150000.0, $this->num($invoice['total_amount']));
        $this->assertSame(150000.0, $this->num($invoice['outstanding_amount']));
        $this->assertSame(PpdbInvoice::STATUS_UNPAID, $invoice['status']);
        $this->assertFalse((bool) data_get($page, 'data.online_available'), 'Midtrans nonaktif tanpa kredensial.');
        $this->assertNotEmpty(data_get($page, 'data.instructions'));

        // 2. Midtrans diminta padahal kredensial kosong → 422 menjelaskan, BUKAN 500.
        $midtrans = $this->as($parent['user'])
            ->postJson("/api/v1/ppdb/portal/invoices/{$invoiceId}/pay", [
                'amount' => 150000,
                'method' => PpdbPayment::METHOD_MIDTRANS,
            ])
            ->assertStatus(422);

        $this->assertStringContainsString('Midtrans', (string) $midtrans->json('errors.method.0'));

        // Callback publik tanpa auth hanya boleh mengubah data bila tanda tangannya
        // sah. Tanpa server key TERPASANG pun notifikasi tanpa tanda tangan ditolak,
        // supaya nomor tagihan tidak bisa dipakai orang asing untuk melunasi tagihan.
        $foreign = $this->postJson('/api/v1/ppdb/payments/midtrans/callback', [
            'transaction_details' => ['order_id' => 'TIDAK-ADA-123', 'gross_amount' => 150000],
            'transaction_status' => 'settlement',
            'status_code' => '200',
        ]);

        $this->assertSame(403, $foreign->status(), 'Callback anonim tidak boleh diproses.');
        $this->assertDatabaseCount('ppdb_payments', 0);

        // 2b. Percobaan nyata: nomor tagihan SAH dipakai tanpa tanda tangan.
        $forged = $this->postJson('/api/v1/ppdb/payments/midtrans/callback', [
            'transaction_details' => [
                'order_id' => (string) $invoice['number'],
                'gross_amount' => '150000',
                'status_code' => '200',
            ],
            'transaction_status' => 'settlement',
        ]);

        $this->assertSame(403, $forged->status());
        $this->assertDatabaseCount('ppdb_payments', 0);
        $this->assertSame(PpdbInvoice::STATUS_UNPAID, $this->invoiceStatus($parent, $registration, $invoiceId));

        // 2c. Mode mock/uji tanpa kredensial tetap tersedia lewat flag eksplisit,
        // dan di jalur itu order_id asing dijawab 422 (bukan 500) tanpa menulis apa pun.
        config(['services.midtrans.allow_unsigned_notifications' => true]);

        $unknown = $this->postJson('/api/v1/ppdb/payments/midtrans/callback', [
            'transaction_details' => ['order_id' => 'TIDAK-ADA-123', 'gross_amount' => 150000],
            'transaction_status' => 'settlement',
            'status_code' => '200',
        ]);

        $this->assertSame(422, $unknown->status());
        $this->assertStringContainsString('tidak ditemukan', (string) $unknown->json('message'));
        $this->assertDatabaseCount('ppdb_payments', 0);

        config(['services.midtrans.allow_unsigned_notifications' => false]);

        // 3. Pembayaran sebagian #1 → pembayaran `pending`.
        $first = $this->submitPayment($parent, $invoiceId, 100000);
        $this->assertSame(PpdbPayment::STATUS_PENDING, $this->paymentRow($first)['status']);

        // Selama bukti bayar belum diputuskan bendahara, keadaannya harus terbaca
        // `pending` pada tagihan DAN pendaftaran — kalau tidak, antrean "menunggu
        // verifikasi" tidak pernah terlihat di portal maupun dashboard panitia.
        $this->assertSame(
            PpdbInvoice::STATUS_PENDING,
            $this->invoiceStatus($parent, $registration, $invoiceId),
        );
        $this->assertSame(
            PpdbRegistration::PAYMENT_PENDING,
            $this->registrationRow($registration)['payment_status'],
        );

        $this->as($this->bendahara)
            ->postJson("/api/v1/ppdb/payments/{$first}/verify", ['note' => 'Setoran pertama masuk.'])
            ->assertOk()
            ->assertJsonPath('data.status', PpdbPayment::STATUS_PAID);

        $partial = $this->invoiceRow($parent, $registration, $invoiceId);
        $this->assertSame(100000.0, $this->num($partial['paid_amount']));
        $this->assertSame(50000.0, $this->num($partial['outstanding_amount']));
        $this->assertSame(PpdbInvoice::STATUS_PENDING, $partial['status'], 'Sebagian terbayar belum lunas.');

        // 4. Pembayaran sebagian #2 lalu DITOLAK → tagihan dihitung ulang + wali dikabari.
        $second = $this->submitPayment($parent, $invoiceId, 50000);

        $this->as($this->bendahara)
            ->postJson("/api/v1/ppdb/payments/{$second}/reject", ['reason' => 'Nominal transfer tidak sama dengan tagihan.'])
            ->assertOk()
            ->assertJsonPath('data.status', PpdbPayment::STATUS_FAILED);

        $afterReject = $this->invoiceRow($parent, $registration, $invoiceId);
        $this->assertSame(100000.0, $this->num($afterReject['paid_amount']), 'Pembayaran yang ditolak tidak boleh ikut terhitung.');
        $this->assertSame(50000.0, $this->num($afterReject['outstanding_amount']));
        $this->assertSame(PpdbInvoice::STATUS_PENDING, $afterReject['status']);
        $this->assertCount(2, (array) data_get($afterReject, 'payments'), 'Dua pengajuan tercatat; yang gagal tetap jejak audit.');

        $types = array_column((array) $this->as($parent['user'])
            ->getJson('/api/v1/ppdb/portal/notifications?per_page=100')->assertOk()->json('data'), 'type');
        $this->assertContains(PpdbActivityNotification::TYPE_PAYMENT_FAILED, $types, 'Penolakan pembayaran harus mengabari wali.');

        // 5. Overpayment ditolak sebelum pelunasan.
        $this->as($parent['user'])
            ->postJson("/api/v1/ppdb/portal/invoices/{$invoiceId}/pay", [
                'amount' => 60000,
                'method' => PpdbPayment::METHOD_MANUAL,
                'payment_method_id' => $this->transfer->id,
                'proof' => UploadedFile::fake()->create('lebihan.jpg', 80, 'image/jpeg'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        // 6. Pelunasan → invoice `paid` + payment_status pendaftaran sinkron.
        $third = $this->submitPayment($parent, $invoiceId, 50000);

        $this->as($this->bendahara)
            ->postJson("/api/v1/ppdb/payments/{$third}/verify", ['note' => 'Sisa tagihan sudah masuk.'])
            ->assertOk();

        $settled = $this->invoiceRow($parent, $registration, $invoiceId);
        $this->assertSame(PpdbInvoice::STATUS_PAID, $settled['status']);
        $this->assertSame(150000.0, $this->num($settled['paid_amount']), 'Dua pembayaran sah terakumulasi.');
        $this->assertSame(0.0, $this->num($settled['outstanding_amount']));
        $this->assertTrue((bool) $settled['is_paid']);
        $this->assertSame(PpdbRegistration::PAYMENT_PAID, $this->registrationRow($registration)['payment_status']);
        $this->assertCount(3, (array) data_get($settled, 'payments'), 'Tiga pengajuan (lunas, gagal, lunas) tersisa sebagai jejak audit.');

        // Bukti bayar benar-benar tertulis di disk.
        $proofPath = (string) $this->paymentRow($third)['proof_path'];
        $this->assertNotSame('', $proofPath);
        $this->assertTrue(Storage::disk('public')->exists($proofPath), "Bukti bayar harus ada di disk: {$proofPath}");

        // Tagihan lunas tidak bisa dibayar lagi.
        $this->as($parent['user'])
            ->postJson("/api/v1/ppdb/portal/invoices/{$invoiceId}/pay", [
                'amount' => 10000,
                'method' => PpdbPayment::METHOD_MANUAL,
                'payment_method_id' => $this->transfer->id,
                'proof' => UploadedFile::fake()->create('lagi.jpg', 60, 'image/jpeg'),
            ])
            ->assertStatus(422);

        // Lunas → wali menerima kabar pembayaran.
        $paidTypes = array_column((array) $this->as($parent['user'])
            ->getJson('/api/v1/ppdb/portal/notifications?per_page=100')->assertOk()->json('data'), 'type');

        $this->assertContains(PpdbActivityNotification::TYPE_PAYMENT_PAID, $paidTypes);
    }

    /**
     * Kelanjutan skenario E dengan kredensial sandbox: transaksi Snap dibuat dari
     * portal, lalu notifikasi Core API ditutup. HTTP difake (tidak ada panggilan
     * keluar) tapi tanda tangan dihitung dengan rumus resmi Midtrans, jadi jalur
     * penyedia yang sebenarnya — termasuk penolakannya — ikut teruji.
     */
    public function test_skenario_e2_midtrans_sandbox_dari_snap_sampai_settlement(): void
    {
        $serverKey = 'SB-Midtrans-Server-Key-Uji';

        config([
            'services.midtrans.server_key' => $serverKey,
            'services.midtrans.client_key' => 'SB-Midtrans-Client-Key',
            'services.midtrans.is_production' => false,
            'services.midtrans.allow_unsigned_notifications' => false,
        ]);

        $parent = $this->registerParent('wali.daring@ppdb-skenario.test', '0813110000051');
        $registration = $this->verifiedRegistration($parent, $this->addApplicant($parent, 'Calan Daring', $this->male, '3301010101010242', '0123000242'));

        $page = $this->as($parent['user'])
            ->getJson("/api/v1/ppdb/portal/registrations/{$registration}/payment")
            ->assertOk()
            ->json();

        $invoice = collect((array) data_get($page, 'data.invoices'))->firstWhere('type', PpdbInvoice::TYPE_REGISTRATION);
        $invoiceId = (int) $invoice['id'];
        $order = (string) $invoice['number'];

        $this->assertTrue((bool) data_get($page, 'data.online_available'), 'Kanal online terbuka bila kredensial tersedia.');

        Http::fake([
            '*/snap/v1/transaction' => Http::response([
                'token' => 'snap-token-uji',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/uji',
                'transaction_details' => ['order_id' => $order, 'gross_amount' => 150000],
            ]),
        ]);

        $checkout = $this->as($parent['user'])
            ->postJson("/api/v1/ppdb/portal/invoices/{$invoiceId}/pay", [
                'amount' => 150000,
                'method' => PpdbPayment::METHOD_MIDTRANS,
            ])
            ->assertCreated()
            ->assertJsonPath('data.next_action', 'redirect_to_gateway')
            ->json();

        $this->assertSame('snap-token-uji', data_get($checkout, 'data.gateway.snap_token'));
        $this->assertSame($order, data_get($checkout, 'data.gateway.order_id'));
        $this->assertSame(PpdbInvoice::STATUS_PENDING, data_get($checkout, 'data.invoice.status'));

        // Permintaan Snap benar-benar keluar ke URL sandbox, memakai server key
        // sebagai kredensial Basic, dan menagih nomor tagihan yang benar.
        Http::assertSent(function ($request) use ($order, $serverKey) {
            $body = (array) json_decode((string) $request->body(), true);
            $auth = (array) $request->header('Authorization');

            $credentials = base64_decode((string) preg_replace('/^Basic\s+/i', '', (string) ($auth[0] ?? '')), true);

            return str_starts_with($request->url(), PpdbMidtransGateway::SANDBOX_URL)
                && $body['transaction_details']['order_id'] === $order
                && (int) $body['transaction_details']['gross_amount'] === 150000
                && $credentials === $serverKey.':';
        });

        // 1. Tanda tangan salah → 403, tidak ada uang yang tercatat.
        $wrong = $this->midtransNotification($order, 'settlement', $serverKey, signature: 'bukan-tanda-tangan-midtrans');

        $this->postJson('/api/v1/ppdb/payments/midtrans/callback', $wrong)->assertStatus(403);
        $this->assertSame(0.0, $this->num($this->invoiceRow($parent, $registration, $invoiceId)['paid_amount']));

        // 2. Header X-Route di luar rute notifikasi → tetap 403 walau tanda tangan sah.
        $signed = $this->midtransNotification($order, 'settlement', $serverKey, transactionId: 'txn-settlement-uji');

        $this->postJson('/api/v1/ppdb/payments/midtrans/callback', $signed, ['X-Route' => '/v3/unknown'])
            ->assertStatus(403);

        // 3. Notifikasi sah → tagihan lunas tanpa verifikasi manual bendahara.
        $this->postJson('/api/v1/ppdb/payments/midtrans/callback', $signed)
            ->assertOk()
            ->assertJsonPath('data.status', 'processed');

        $settled = $this->invoiceRow($parent, $registration, $invoiceId);
        $this->assertSame(PpdbInvoice::STATUS_PAID, $settled['status']);
        $this->assertSame(150000.0, $this->num($settled['paid_amount']));
        $this->assertSame(0.0, $this->num($settled['outstanding_amount']));
        $this->assertSame(PpdbRegistration::PAYMENT_PAID, $this->registrationRow($registration)['payment_status']);

        $payment = $this->paymentRow((int) data_get($checkout, 'data.payment.id'));
        $this->assertSame(PpdbPayment::STATUS_PAID, $payment['status']);
        $this->assertSame('midtrans', $payment['provider']);
        $this->assertSame('txn-settlement-uji', $payment['provider_reference']);

        // 4. Idempoten: callback yang sama diulang tidak menambah `paid_amount`.
        $this->postJson('/api/v1/ppdb/payments/midtrans/callback', $signed)
            ->assertOk()
            ->assertJsonPath('data.status', 'stale');

        $this->assertSame(150000.0, $this->num($this->invoiceRow($parent, $registration, $invoiceId)['paid_amount']));

        // 5. Wali dikabari lewat kanal notifikasi existing.
        $types = array_column((array) $this->as($parent['user'])
            ->getJson('/api/v1/ppdb/portal/notifications?per_page=100')->assertOk()->json('data'), 'type');

        $this->assertContains(PpdbActivityNotification::TYPE_PAYMENT_PAID, $types);
    }

    /**
     * Payload notifikasi Core API; `$signature` null dihitung dengan rumus resmi
     * `SHA512(order_id + status_code + gross_amount + server_key)`.
     *
     * @return array<string, mixed>
     */
    private function midtransNotification(string $order, string $status, string $serverKey, ?string $signature = null, string $transactionId = 'txn-uji'): array
    {
        $statusCode = $status === 'settlement' ? '200' : '401';
        $gross = '150000';

        return [
            'transaction_id' => $transactionId,
            'transaction_status' => $status,
            'status_code' => $statusCode,
            'gross_amount' => $gross,
            'transaction_details' => [
                'order_id' => $order,
                'gross_amount' => $gross,
                'status_code' => $statusCode,
            ],
            'signature' => $signature ?? hash('sha512', $order.$statusCode.$gross.$serverKey),
        ];
    }

    // =================================================================
    // SKENARIO F — bobot 100%, nilai gabungan + peringkat, penjaga kuota
    // =================================================================

    public function test_skenario_f_bobot_nilai_gabungan_dan_penjaga_kuota_seleksi(): void
    {
        $parentA = $this->registerParent('wali.nilai-a@ppdb-skenario.test', '081311000006');
        $parentB = $this->registerParent('wali.nilai-b@ppdb-skenario.test', '081311000007');

        $a = $this->verifiedRegistration($parentA, $this->addApplicant($parentA, 'Calan A', $this->male, '3301010101010251', '0123000251'));
        $b = $this->verifiedRegistration($parentB, $this->addApplicant($parentB, 'Calan B', $this->female, '3301010101010252', '0123000252'));

        $this->as($this->panitia);

        $academic = $this->stage('Tes Akademik', PpdbSelectionStage::TYPE_ACADEMIC_TEST, 50.0);
        $tahfidz = $this->stage('Tes Tahfidz', PpdbSelectionStage::TYPE_TAHFIDZ, 30.0);
        $interview = $this->stage('Wawancara', PpdbSelectionStage::TYPE_INTERVIEW, 20.0);

        // 1. Bobot harus tepat 100% — 90% ditolak, tanpa normalisasi diam-diam.
        $refused = $this->putJson('/api/v1/ppdb/selection-stages/weights', [
            'period_id' => $this->period->id,
            'weights' => [
                ['stage_id' => $academic->id, 'weight' => 50],
                ['stage_id' => $tahfidz->id, 'weight' => 30],
                ['stage_id' => $interview->id, 'weight' => 10],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('weights');

        $this->assertStringContainsString('harus tepat 100', (string) $refused->json('errors.weights.0'));

        // Sebagian tahap saja juga ditolak.
        $this->putJson('/api/v1/ppdb/selection-stages/weights', [
            'period_id' => $this->period->id,
            'weights' => [['stage_id' => $academic->id, 'weight' => 100]],
        ])->assertStatus(422);

        $synced = $this->putJson('/api/v1/ppdb/selection-stages/weights', [
            'period_id' => $this->period->id,
            'weights' => [
                ['stage_id' => $academic->id, 'weight' => 50],
                ['stage_id' => $tahfidz->id, 'weight' => 30],
                ['stage_id' => $interview->id, 'weight' => 20],
            ],
        ])->assertOk()->assertJsonPath('data.summary.valid', true);

        $this->assertSame(100.0, $this->num($synced->json('data.summary.total')));

        // 2. Nilai per tahap → skor gabungan berbobot + urutan peringkat.
        $aFirst = $this->enrolAndScore($academic, $a, 80);
        $this->enrolAndScore($tahfidz, $a, 90);
        $this->enrolAndScore($interview, $a, 70);

        $bFirst = $this->enrolAndScore($academic, $b, 60);
        $this->enrolAndScore($tahfidz, $b, 80);
        $this->enrolAndScore($interview, $b, 90);

        // Dibaca ulang SETELAH tiga tahap dinilai: `composite` dihitung dari
        // tahap yang sudah dinilai saja — Σ(nilai × bobot) / Σ(bobot terukur).
        $aComposite = $this->participantRow((int) $aFirst['id'])['composite'];
        $bComposite = $this->participantRow((int) $bFirst['id'])['composite'];

        $this->assertTrue($aComposite['weight_valid']);
        $this->assertSame(100.0, $this->num($aComposite['total_weight']));

        // (80×50 + 90×30 + 70×20) / 100 = 81 ; (60×50 + 80×30 + 90×20) / 100 = 72.
        $this->assertEqualsWithDelta(81.0, $this->num($aComposite['score']), 0.01);
        $this->assertEqualsWithDelta(72.0, $this->num($bComposite['score']), 0.01);
        $this->assertCount(3, (array) $aComposite['stages']);

        $participants = (array) $this->getJson("/api/v1/ppdb/selection-stages/{$academic->id}/participants")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $participants);
        $this->assertSame($a, (int) $participants[0]['registration_id'], 'Peringkat = nilai gabungan tertinggi lebih dulu.');
        $this->assertSame($b, (int) $participants[1]['registration_id']);
        $this->assertGreaterThan(
            $this->num($participants[1]['composite']['score']),
            $this->num($participants[0]['composite']['score']),
        );

        // Orang tua melihat nilai + skor gabungannya sendiri, tanpa pesaing lain.
        $own = $this->as($parentA['user'])
            ->getJson("/api/v1/ppdb/portal/registrations/{$a}/selection")
            ->assertOk()
            ->assertJsonCount(3, 'data.stages')
            ->assertJsonPath('data.selection_result', null)
            ->json();

        $this->assertEqualsWithDelta(81.0, $this->num(data_get($own, 'data.composite.score')), 0.01);
        $this->assertSame($a, (int) data_get($own, 'data.registration_id'));

        // 3. Penjaga kuota: persempit kursi jadi 1 lewat konfigurasi sah.
        $this->as($this->kepala)->putJson("/api/v1/ppdb/programs/{$this->program->id}", ['quota' => 1])->assertOk();

        $this->as($this->panitia);

        $this->postJson("/api/v1/ppdb/registrations/{$a}/result", ['result' => PpdbRegistration::STATUS_PASSED])
            ->assertOk()
            ->assertJsonPath('data.quota.over_quota', false);

        // Kursi habis → 422 tanpa alasan.
        $overQuota = $this->postJson("/api/v1/ppdb/registrations/{$b}/result", ['result' => PpdbRegistration::STATUS_PASSED])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quota');
        $this->assertNotEmpty($overQuota->json('errors.quota'));

        // Alasan saja tidak cukup: panitia biasa tidak memegang hak override.
        $this->postJson("/api/v1/ppdb/registrations/{$b}/result", [
            'result' => PpdbRegistration::STATUS_PASSED,
            'quota_override_reason' => 'Tambahan kursi atas keputusan rapat pimpinan daerah.',
        ])->assertForbidden();

        // Pemegang hak tapi tanpa alasan → tetap 422.
        $this->as($this->kepala)
            ->postJson("/api/v1/ppdb/registrations/{$b}/result", ['result' => PpdbRegistration::STATUS_PASSED])
            ->assertStatus(422);

        // Hak + alasan → 200, alasan tercatat + terbaca di riwayat.
        $reason = 'Tambahan kursi atas keputusan rapat pimpinan daerah.';

        $overridden = $this->as($this->kepala)
            ->postJson('/api/v1/ppdb/registrations/'.$b.'/result', [
                'result' => PpdbRegistration::STATUS_PASSED,
                'quota_override_reason' => $reason,
            ])
            ->assertOk()
            ->assertJsonPath('data.quota.over_quota', true)
            ->assertJsonPath('data.registration.status', PpdbRegistration::STATUS_PASSED);

        $this->assertStringContainsString('MELEWATI KUOTA', (string) $overridden->json('message'));
        $this->assertDatabaseHas('ppdb_registrations', [
            'id' => $b,
            'quota_override_reason' => $reason,
        ]);

        $history = (array) $this->as($this->kepala)->getJson("/api/v1/ppdb/registrations/{$b}/history")->assertOk()->json('data');
        $this->assertNotEmpty(array_filter(
            $history,
            static fn (array $row): bool => str_contains((string) $row['note'], $reason),
        ), 'Alasan override harus tercatat pada riwayat.');
    }

    // =================================================================
    // SKENARIO G — isolasi hasil, sanggah, daftar ulang, konversi, laporan
    // =================================================================

    public function test_skenario_g_hasil_terkunci_sanggah_daftar_ulang_konversi_dan_laporan(): void
    {
        $owner = $this->registerParent('wali.hasil-g@ppdb-skenario.test', '081311000008');
        $other = $this->registerParent('wali.orang-lain@ppdb-skenario.test', '081311000010');

        $passed = $this->verifiedRegistration($owner, $this->addApplicant($owner, 'Calan Lulus', $this->male, '3301010101010261', '0123000261'));
        $failed = $this->verifiedRegistration($owner, $this->addApplicant($owner, 'Calan Gagal', $this->female, '3301010101010262', '0123000262'));
        $foreign = $this->verifiedRegistration($other, $this->addApplicant($other, 'Calan Milik Orang Lain', $this->male, '3301010101010263', '0123000263'));

        $announcement = (int) $this->as($this->panitia)
            ->postJson('/api/v1/ppdb/announcements', [
                'period_id' => $this->period->id,
                'title' => 'Pengumuman Hasil Seleksi Skenario G',
                'body' => 'Selamat kepada calon yang dinyatakan lulus.',
                'channel' => PpdbAnnouncement::CHANNEL_PORTAL,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->as($this->panitia)->postJson("/api/v1/ppdb/announcements/{$announcement}/publish")->assertOk();

        $this->postJson("/api/v1/ppdb/registrations/{$passed}/result", [
            'result' => PpdbRegistration::STATUS_PASSED,
            'announcement_id' => $announcement,
            'note' => 'Lulus jalur reguler.',
        ])->assertOk();

        $this->postJson("/api/v1/ppdb/registrations/{$failed}/result", [
            'result' => PpdbRegistration::STATUS_FAILED,
            'announcement_id' => $announcement,
            'note' => 'Nilai gabungan di bawah passing grade.',
        ])->assertOk();

        // 1. Hasil hanya milik wali pemilik pendaftaran.
        $own = $this->as($owner['user'])
            ->getJson("/api/v1/ppdb/portal/registrations/{$passed}/selection")
            ->assertOk()
            ->assertJsonPath('data.selection_result', PpdbRegistration::STATUS_PASSED);

        $this->assertSame($passed, (int) $own->json('data.registration_id'));

        foreach (['selection', 'payment', 'appeals'] as $path) {
            $this->as($other['user'])
                ->getJson("/api/v1/ppdb/portal/registrations/{$passed}/{$path}")
                ->assertForbidden()
                ->assertJsonPath('message', 'Pendaftaran tersebut bukan milik Anda.');
        }

        $this->as($other['user'])->getJson("/api/v1/ppdb/portal/registrations/{$foreign}/selection")->assertOk();

        // 2. Sanggah diterima TIDAK mengubah hasil, hanya membuka keputusan.
        $this->as($owner['user'])
            ->postJson("/api/v1/ppdb/portal/registrations/{$failed}/appeals", [
                'reason' => 'Nilai tes tahfidz saya belum masuk pada tampilan hasil.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PpdbRegistrationAppeal::STATUS_PENDING);

        $appealId = (int) $this->as($this->panitia)->getJson('/api/v1/ppdb/appeals')->assertOk()->json('data.0.id');

        $decided = $this->as($this->panitia)
            ->postJson("/api/v1/ppdb/appeals/{$appealId}/decide", [
                'decision' => 'accepted',
                'review_note' => 'Nilai tahfidz telah diperbaiki, keputusan dibuka kembali.',
            ])
            ->assertOk();

        $decided->assertJsonPath('data.result_changed', false)
            ->assertJsonPath('data.selection_result', PpdbRegistration::STATUS_FAILED)
            ->assertJsonPath('data.appeal.status', PpdbRegistrationAppeal::STATUS_ACCEPTED);

        $failedRow = $this->registrationRow($failed);
        $this->assertSame(PpdbRegistration::STATUS_SELECTION, $failedRow['status'], 'Yang dibuka kembali adalah status keputusannya.');
        $this->assertSame(PpdbRegistration::STATUS_FAILED, $failedRow['selection_result'], 'Hasil seleksi tidak boleh berubah otomatis.');

        // 3. Daftar ulang tidak boleh selesai sebelum tagihannya lunas.
        $opened = $this->as($this->panitia)
            ->postJson("/api/v1/ppdb/registrations/{$passed}/re-registration/open", ['note' => 'Silakan selesaikan daftar ulang.'])
            ->assertOk();

        $opened->assertJsonPath('data.registration.status', PpdbRegistration::STATUS_RE_REGISTRATION);
        $reInvoice = (int) $opened->json('data.invoice.id');
        $this->assertSame(250000.0, $this->num($opened->json('data.invoice.total_amount')));

        $this->as($this->panitia)
            ->postJson("/api/v1/ppdb/registrations/{$passed}/re-registration/complete")
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice');

        $this->as($this->kepala)
            ->postJson("/api/v1/ppdb/registrations/{$passed}/convert-to-student")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        // 4. Lunasi tagihan daftar ulang → baru boleh selesai.
        $this->pay($owner, $reInvoice, 250000);

        $this->as($this->panitia)
            ->postJson("/api/v1/ppdb/registrations/{$passed}/re-registration/complete")
            ->assertOk()
            ->assertJsonPath('data.registration.status', PpdbRegistration::STATUS_COMPLETED);

        // 5. Konversi: satu baris siswa, wali existing ditautkan, tanpa user baru.
        $guardianId = (int) $this->as($owner['user'])->getJson('/api/v1/ppdb/portal/profile')->assertOk()->json('data.parent.guardian_id');
        $this->assertGreaterThan(0, $guardianId);

        $usersBefore = User::query()->count();
        $guardiansBefore = Guardian::query()->count();

        $converted = $this->as($this->kepala)
            ->postJson("/api/v1/ppdb/registrations/{$passed}/convert-to-student", ['nis' => '900246'])
            ->assertCreated();

        $converted->assertJsonPath('data.created', true)
            ->assertJsonPath('data.student.nis', '900246')
            ->assertJsonPath('data.student.full_name', 'Calan Lulus');

        $studentId = (int) $converted->json('data.student.id');

        $this->assertSame(1, Student::query()->count(), 'Konversi menghasilkan tepat satu baris siswa.');
        $this->assertSame($usersBefore, User::query()->count(), 'Konversi tidak boleh membuat user baru.');
        $this->assertSame($guardiansBefore, \App\Models\Guardian::query()->count(), 'Konversi memakai wali yang sudah ada.');

        $this->assertDatabaseCount('student_guardians', 1);
        $this->assertDatabaseHas('student_guardians', ['student_id' => $studentId, 'guardian_id' => $guardianId]);
        $this->assertSame($studentId, (int) $this->registrationRow($passed)['student_id']);

        // 6. Kabar hasil hanya ke user wali pemilik; tidak ke siapa pun.
        $recipients = User::query()
            ->whereHas('notifications', fn ($q) => $q
                ->where('type', PpdbActivityNotification::class)
                ->where('data->type', PpdbActivityNotification::TYPE_SELECTION_RESULT))
            ->pluck('id')
            ->all();

        $this->assertSame([(int) $owner['user']->id], $recipients, 'Notifikasi hasil harus mendarat hanya di pemiliknya.');

        // 7. Laporan & dashboard: 200 untuk panitia, 403 untuk peran tanpa hak.
        $this->as($this->panitia);

        $this->getJson('/api/v1/ppdb/dashboard?period_id='.$this->period->id)->assertOk();

        foreach (['registration', 'payment', 'selection', 'document'] as $report) {
            $this->getJson('/api/v1/ppdb/reports/'.$report.'?period_id='.$this->period->id)->assertOk();
        }

        // Peran tak berhak (wali murid) tertutup rapat di sisi admin.
        $this->as($other['user'])->getJson('/api/v1/ppdb/dashboard')->assertForbidden();
        $this->as($other['user'])->getJson('/api/v1/ppdb/reports/registration')->assertForbidden();
        $this->as($other['user'])->getJson('/api/v1/ppdb/registrations')->assertForbidden();
    }

    // =================================================================
    // HELPER
    // =================================================================

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
            'address' => 'Jl. Skenario Uji No. 1, Bandung',
        ])->assertCreated();

        return [
            'user' => User::query()->firstWhere('email', $email),
            'token' => (string) $created->json('data.token'),
        ];
    }

    /** @param  User|array{user: User, token: string}  $parent */
    private function addApplicant(User|array $parent, string $name, Gender $gender, string $nik, string $nisn): int
    {
        $user = $parent instanceof User ? $parent : $parent['user'];

        return (int) $this->as($user)
            ->postJson('/api/v1/ppdb/portal/applicants', [
                'full_name' => $name,
                'nickname' => substr($name, 0, 6),
                'gender_id' => $gender->id,
                'birth_place' => 'Bandung',
                'birth_date' => '2010-04-01',
                'nik' => $nik,
                'nisn' => $nisn,
                'previous_school' => 'SMP Skenario',
                'address' => 'Jl. Skenario Uji No. 1',
                'target_program_id' => $this->program->id,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    /** @param  User|array{user: User, token: string}  $parent */
    private function createRegistration(User|array $parent, int $applicantId, ?PpdbProgram $program = null): int
    {
        $user = $parent instanceof User ? $parent : $parent['user'];

        return (int) $this->as($user)
            ->postJson('/api/v1/ppdb/portal/registrations', [
                'applicant_id' => $applicantId,
                'period_id' => $this->period->id,
                'program_id' => ($program ?? $this->program)->id,
                'wave_id' => $this->wave->id,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    /** @param  User|array{user: User, token: string}  $parent */
    private function uploadDocument(User|array $parent, int $registrationId): void
    {
        $user = $parent instanceof User ? $parent : $parent['user'];

        $this->as($user)
            ->postJson("/api/v1/ppdb/portal/registrations/{$registrationId}/documents", [
                'requirement_id' => $this->requirement->id,
                'file' => UploadedFile::fake()->create('kk.pdf', 120, 'application/pdf'),
            ])
            ->assertSuccessful();
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

    /** Draft → submitted → berkas disetujui → verified, semuanya lewat HTTP. */
    private function verifiedRegistration(array $parent, int $applicantId, ?PpdbProgram $program = null): int
    {
        $registration = $this->createRegistration($parent['user'], $applicantId, $program);

        $this->uploadDocument($parent['user'], $registration);

        $this->as($parent['user'])
            ->postJson("/api/v1/ppdb/portal/registrations/{$registration}/submit")
            ->assertOk();

        $this->as($this->panitia);

        $this->postJson("/api/v1/ppdb/registrations/{$registration}/transition", [
            'status' => PpdbRegistration::STATUS_UNDER_REVIEW,
            'note' => 'Berkas sedang diteliti panitia.',
        ])->assertOk();

        foreach ($this->documentsOf($registration) as $document) {
            $this->postJson("/api/v1/ppdb/documents/{$document['id']}/review", ['status' => PpdbApplicantDocument::STATUS_APPROVED])->assertOk();
        }

        $this->postJson("/api/v1/ppdb/registrations/{$registration}/verify", ['note' => 'Lengkap.'])->assertOk();

        return $registration;
    }

    /** Bayar lewat portal lalu verifikasi oleh bendahara. */
    private function pay(array $parent, int $invoiceId, float $amount): int
    {
        $payment = $this->submitPayment($parent, $invoiceId, $amount);

        $this->as($this->bendahara)
            ->postJson("/api/v1/ppdb/payments/{$payment}/verify", ['note' => 'Uang sudah masuk rekening.'])
            ->assertOk();

        return $payment;
    }

    /** @return int id pembayaran yang baru diajukan (status `pending`) */
    private function submitPayment(array $parent, int $invoiceId, float $amount): int
    {
        return (int) $this->as($parent['user'])
            ->postJson("/api/v1/ppdb/portal/invoices/{$invoiceId}/pay", [
                'amount' => $amount,
                'method' => PpdbPayment::METHOD_MANUAL,
                'payment_method_id' => $this->transfer->id,
                'reference' => 'JB-'.uniqid(),
                'proof' => UploadedFile::fake()->create('bukti.jpg', 90, 'image/jpeg'),
            ])
            ->assertSuccessful()
            ->json('data.payment.id');
    }

    /** @param  int|string  $paymentId */
    private function paymentRow(int $paymentId): array
    {
        return collect((array) $this->as($this->bendahara)
            ->getJson('/api/v1/ppdb/payments?all=1')->assertOk()->json('data'))
            ->firstWhere('id', $paymentId) ?? [];
    }

    /** Baris tagihan seperti dilihat wali pada halaman pembayarannya. */
    private function invoiceRaw(array $parent, int $registrationId, int $invoiceId): array
    {
        $invoices = (array) $this->as($parent['user'])
            ->getJson("/api/v1/ppdb/portal/registrations/{$registrationId}/payment")
            ->assertOk()
            ->json('data.invoices');

        return (array) (collect($invoices)->firstWhere('id', $invoiceId) ?? []);
    }

    private function invoiceRow(array $parent, int $registrationId, int $invoiceId): array
    {
        $invoice = $this->invoiceRaw($parent, $registrationId, $invoiceId);

        $this->assertNotSame([], $invoice, "Tagihan {$invoiceId} harus terlihat pada portal wali.");

        return $invoice;
    }

    private function invoiceStatus(array $parent, int $registrationId, int $invoiceId): string
    {
        return (string) $this->invoiceRow($parent, $registrationId, $invoiceId)['status'];
    }

    private function stage(string $name, string $type, float $weight): PpdbSelectionStage
    {
        $id = $this->as($this->panitia)
            ->postJson('/api/v1/ppdb/selection-stages', [
                'period_id' => $this->period->id,
                'name' => $name,
                'type' => $type,
                'weight' => $weight,
                'scheduled_at' => now()->addDays(3)->toDateTimeString(),
                'location' => 'Ruang '.$name,
                'passing_score' => 70,
            ])
            ->assertCreated()
            ->json('data.id');

        return PpdbSelectionStage::query()->findOrFail((int) $id);
    }

    /** Daftarkan satu pendaftaran sebagai peserta lalu beri nilai. */
    private function enrolAndScore(PpdbSelectionStage $stage, int $registrationId, float $score): array
    {
        $this->as($this->panitia);

        $created = $this->postJson("/api/v1/ppdb/selection-stages/{$stage->id}/participants", [
            'registration_ids' => [$registrationId],
        ])->assertOk()->json('data.created.0.id');

        $participantId = (int) ($created ?? $this->participantOf($stage, $registrationId)['id']);

        $this->postJson("/api/v1/ppdb/selection-participants/{$participantId}/score", [
            'score' => $score,
            'status' => PpdbSelectionParticipant::STATUS_SCORED,
        ])->assertOk();

        return $this->participantRow($participantId);
    }

    private function participantOf(PpdbSelectionStage $stage, int $registrationId): array
    {
        $rows = $this->as($this->panitia)
            ->getJson("/api/v1/ppdb/selection-stages/{$stage->id}/participants")
            ->assertOk()
            ->json('data');

        return collect((array) $rows)->firstWhere('registration_id', $registrationId) ?? [];
    }

    /** @return array<string, mixed> */
    private function participantRow(int $participantId): array
    {
        $stage = PpdbSelectionParticipant::query()->findOrFail($participantId)->stage_id;

        return collect((array) $this->as($this->panitia)
            ->getJson("/api/v1/ppdb/selection-stages/{$stage}/participants")
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', $participantId) ?? [];
    }

    /** @return array<string, mixed> */
    private function registrationRow(int $registrationId): array
    {
        $rows = (array) $this->as($this->panitia)
            ->getJson('/api/v1/ppdb/registrations?period_id='.$this->period->id.'&per_page=200')
            ->assertOk()
            ->json('data');

        return collect($rows)->firstWhere('id', $registrationId) ?? [];
    }

    private function num(mixed $value): float
    {
        return (float) $value;
    }
}
