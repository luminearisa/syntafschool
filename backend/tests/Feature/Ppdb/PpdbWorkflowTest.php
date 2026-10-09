<?php

namespace Tests\Feature\Ppdb;

use App\Models\Gender;
use App\Models\PpdbAnnouncement;
use App\Models\PpdbApplicantDocument;
use App\Models\PpdbDocumentRequirement;
use App\Models\PpdbInvoice;
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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Alur end-to-end PPDB lewat HTTP — bagian seleksi → pengumuman → sanggah →
 * daftar ulang → konversi siswa yang tidak tercakup iris lain.
 *
 * Aturan main test ini (audit §41): state boleh berubah HANYA lewat endpoint,
 * tidak ada manipulasi database untuk memindahkan status. Yang diuji:
 * - satu akun orang tua mendaftarkan dua anak (satu user, bukan user per anak);
 * - berkas calon benar-benar tersimpan + dapat direview panitia;
 * - pembayaran manual + verifikasi bendahara melunasi tagihan;
 * - bobot tahap wajib tepat 100% (tanpa normalisasi otomatis);
 * - penetapan `passed` dijaga kuota: 422 tanpa alasan, 403 tanpa hak
 *   `ppdb.registration.override_quota`, dan alasan override tersimpan;
 * - sanggah yang diterima TIDAK mengubah hasil seleksi;
 * - daftar ulang menuntut pembayaran, konversi menuntut daftar ulang selesai;
 * - orang tua terisolasi pada datanya sendiri.
 */
class PpdbWorkflowTest extends TestCase
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

        // Berkas PPDB (dokumen calon, bukti bayar, lampiran sanggah) disimpan di
        // disk `public` — dipindah ke disk sementara supaya test benar-benar
        // bisa membuktikan file mendarat, bukan hanya baris database.
        Storage::fake('public');

        $this->seed(RolePermissionSeeder::class);

        $this->male = Gender::query()->create(['code' => 'M', 'name' => 'Laki-laki']);
        $this->female = Gender::query()->create(['code' => 'F', 'name' => 'Perempuan']);
        $this->transfer = PaymentMethod::query()->create(['code' => 'TRF', 'name' => 'Transfer Bank']);

        $this->period = PpdbPeriod::query()->create([
            'code' => 'PPDB-WF', 'name' => 'PPDB Alur Uji', 'slug' => 'ppdb-alur-uji',
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
            'period_id' => $this->period->id, 'code' => 'GW-UJI', 'name' => 'Gelombang Uji',
            'quota' => 20, 'priority' => 1,
            'registration_start' => now()->subMonth()->toDateString(),
            'registration_end' => now()->addMonth()->toDateString(),
        ]);

        $this->program = PpdbProgram::query()->create([
            'period_id' => $this->period->id, 'code' => 'REG', 'name' => 'Program Reguler',
            'quota' => 5, 'sort_order' => 1,
        ]);

        // Program berkuota 1: dipakai menguji penjaga kuota.
        $this->tight = PpdbProgram::query()->create([
            'period_id' => $this->period->id, 'code' => 'KETAT', 'name' => 'Program Kuota Satu',
            'quota' => 1, 'sort_order' => 2,
        ]);

        $this->requirement = PpdbDocumentRequirement::query()->create([
            'period_id' => $this->period->id, 'code' => 'KK', 'name' => 'Kartu Keluarga',
            'required' => true, 'active' => true, 'order' => 1,
            'file_types' => 'jpg,jpeg,png,pdf', 'max_size_kb' => 1024,
        ]);

        // Biaya pendaftaran + biaya daftar ulang (tipe `other` ditagih pada
        // tagihan daftar ulang karena belum pernah tertagih).
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
    // ALUR PENUH
    // =================================================================

    public function test_alur_penuh_orang_tua_mendaftar_sampai_anak_menjadi_siswa(): void
    {
        $parent = $this->registerParent('wati@ppdb-alur.test', '081300000001');

        // SATU akun untuk dua anak — tidak ada user baru per anak.
        $first = $this->addApplicant($parent, 'Anak Pertama', $this->male, '3301010101010101', '0123000001');
        $second = $this->addApplicant($parent, 'Anak Kedua', $this->female, '3301010101010102', '0123000002');

        $this->assertDatabaseCount('users', 4); // 1 orang tua + 3 petugas

        $one = $this->createRegistration($parent, $first);
        $two = $this->createRegistration($parent, $second, $this->tight);

        $this->assertSame(PpdbRegistration::STATUS_DRAFT, $this->registrationRow($one)['status']);

        foreach ([$one, $two] as $id) {
            $this->uploadDocument($parent, $id);
        }

        // Dokumen benar-benar tersimpan di disk, bukan hanya tercatat di tabel.
        $documents = $this->documentsOf($one);
        $this->assertCount(1, $documents);
        $this->assertTrue(Storage::disk('public')->exists((string) $documents->first()['file_path']));

        foreach ([$one, $two] as $id) {
            $this->as($parent)->postJson("/api/v1/ppdb/portal/registrations/{$id}/submit")->assertOk();
        }

        $this->assertSame(PpdbRegistration::STATUS_SUBMITTED, $this->registrationRow($one)['status']);
        // Dua pendaftaran dari satu akun orang tua — keduanya terhitung.
        $this->assertSame(2, $this->publicRegisteredCount());

        // Panitia: berkas disetujui, pendaftaran diverifikasi. Mesin status
        // menuntut `submitted → under_review → verified` (tidak boleh lompat).
        $this->as($this->panitia);

        foreach ([$one, $two] as $id) {
            $this->postJson("/api/v1/ppdb/registrations/{$id}/transition", [
                'status' => PpdbRegistration::STATUS_UNDER_REVIEW,
                'note' => 'Berkas sedang diteliti panitia.',
            ])->assertOk();

            foreach ($this->documentsOf($id) as $document) {
                $this->postJson("/api/v1/ppdb/documents/{$document['id']}/review", [
                    'status' => PpdbApplicantDocument::STATUS_APPROVED,
                ])->assertOk();
            }

            $this->postJson("/api/v1/ppdb/registrations/{$id}/verify", ['note' => 'Berkas lengkap.'])->assertOk();
        }

        foreach ([$one, $two] as $id) {
            $this->assertSame(PpdbRegistration::STATUS_VERIFIED, $this->registrationRow($id)['status']);
        }

        // Seleksi: dua tahap, bobot awal 80/20 lalu disusun ulang 60/40.
        $test = $this->stage('Tes Akademik', PpdbSelectionStage::TYPE_ACADEMIC_TEST, 80.0);
        $interview = $this->stage('Wawancara', PpdbSelectionStage::TYPE_INTERVIEW, 20.0);

        $this->putJson('/api/v1/ppdb/selection-stages/weights', [
            'period_id' => $this->period->id,
            'weights' => [
                ['stage_id' => $test->id, 'weight' => 60],
                ['stage_id' => $interview->id, 'weight' => 40],
            ],
        ])->assertOk()->assertJsonPath('data.summary.valid', true);

        $participantOne = $this->enrolAndScore($test, $one, 90);
        $this->enrolAndScore($interview, $one, 70);

        // Sebelum ada hasil, orang tua hanya melihat jadwal + nilai per tahap.
        $selection = $this->as($parent)
            ->getJson("/api/v1/ppdb/portal/registrations/{$one}/selection")
            ->assertOk()
            ->assertJsonCount(2, 'data.stages')
            ->assertJsonPath('data.selection_result', null)
            ->json();

        // `json_encode` menuliskan 90.0 sebagai 90, jadi nilainya dibandingkan sebagai angka.
        $this->assertEqualsWithDelta(90.0, $this->num(data_get($selection, 'data.stages.0.participant.score')), 0.01);

        $this->assertNotNull($participantOne['composite']);

        // Pengumuman + penetapan hasil.
        $announcement = $this->as($this->panitia)
            ->postJson('/api/v1/ppdb/announcements', [
                'period_id' => $this->period->id,
                'title' => 'Hasil Seleksi Gelombang Uji',
                'body' => 'Selamat kepada calon yang dinyatakan lulus.',
                'channel' => PpdbAnnouncement::CHANNEL_PORTAL,
            ])
            ->assertCreated()
            ->json('data.id');

        $published = $this->postJson("/api/v1/ppdb/announcements/{$announcement}/publish")->assertOk();
        $this->assertSame(1, $published->json('data.delivered'));
        $this->assertSame(PpdbAnnouncement::CHANNEL_PORTAL, array_key_first($published->json('data.channels')));

        $this->postJson("/api/v1/ppdb/registrations/{$one}/result", [
            'result' => PpdbRegistration::STATUS_PASSED,
            'announcement_id' => $announcement,
            'note' => 'Ranking 1.',
        ])->assertOk()->assertJsonPath('data.registration.status', PpdbRegistration::STATUS_PASSED);

        // Daftar ulang: dibuka → ditagih → dibayar → diverifikasi → selesai.
        $opened = $this->as($this->panitia)
            ->postJson("/api/v1/ppdb/registrations/{$one}/re-registration/open", ['note' => 'Silakan daftar ulang.'])
            ->assertOk();

        $opened->assertJsonPath('data.registration.status', PpdbRegistration::STATUS_RE_REGISTRATION)
            ->assertJsonPath('data.opened', true);

        $reInvoice = $opened->json('data.invoice.id');
        $this->assertSame(250000.0, $this->num($opened->json('data.invoice.total_amount')));

        $this->pay($parent, $reInvoice, 250000);

        $this->as($this->panitia)
            ->postJson("/api/v1/ppdb/registrations/{$one}/re-registration/complete")
            ->assertOk()
            ->assertJsonPath('data.registration.status', PpdbRegistration::STATUS_COMPLETED);

        // Konversi menjadi siswa.
        $converted = $this->as($this->kepala)
            ->postJson("/api/v1/ppdb/registrations/{$one}/convert-to-student", ['nis' => '900123'])
            ->assertCreated();

        $converted->assertJsonPath('data.created', true)
            ->assertJsonPath('data.student.nis', '900123')
            ->assertJsonPath('data.student.full_name', 'Anak Pertama');

        $this->assertSame(1, Student::query()->count());
        $this->assertSame((int) $converted->json('data.student.id'), (int) $this->registrationRow($one)['student_id']);

        // Satu orang tua = satu user: anak kedua belum menjadi siswa.
        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseCount('students', 1);

        // Orang tua membaca hasil + kabar lewat notifikasi portal.
        $notifications = $this->as($parent)
            ->getJson('/api/v1/ppdb/portal/notifications?per_page=100')
            ->assertOk()
            ->json('data');

        $types = array_column($notifications, 'type');

        $this->assertContains('selection_result', $types);
        $this->assertContains('re_registration_open', $types);

        // Kabar hasil seleksi hanya mendarat di orang tua pemilik pendaftarannya.
        // `parent_account_id` dan `users.id` adalah dua ruang nomor berbeda — kalau
        // keduanya tertukar, notifikasi ini yang lebih dulu bocor ke akun lain.
        $recipients = User::query()
            ->whereHas('notifications', fn ($q) => $q
                ->where('type', PpdbActivityNotification::class)
                ->where('data->type', PpdbActivityNotification::TYPE_SELECTION_RESULT))
            ->pluck('id')
            ->all();

        $this->assertSame([(int) $parent['user']->id], $recipients);

        // Anak kedua masih `verified` dan bisa ikut seleksi tanpa mengganggu yang pertama.
        $this->assertSame(PpdbRegistration::STATUS_VERIFIED, $this->registrationRow($two)['status']);
    }

    // =================================================================
    // BOBOT TAHAP (keputusan: wajib tepat 100%)
    // =================================================================

    public function test_bobot_tahap_ditolak_sampai_totalnya_tepat_seratus(): void
    {
        $this->as($this->panitia);

        $academic = $this->stage('Akademik', PpdbSelectionStage::TYPE_ACADEMIC_TEST, 60.0);
        $tahfidz = $this->stage('Tahfidz', PpdbSelectionStage::TYPE_TAHFIDZ, 30.0);

        // Total 120% ditolak.
        $this->putJson('/api/v1/ppdb/selection-stages/weights', [
            'period_id' => $this->period->id,
            'weights' => [
                ['stage_id' => $academic->id, 'weight' => 70],
                ['stage_id' => $tahfidz->id, 'weight' => 50],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('weights');

        // 90% juga ditolak: tidak ada normalisasi otomatis.
        $refused = $this->putJson('/api/v1/ppdb/selection-stages/weights', [
            'period_id' => $this->period->id,
            'weights' => [
                ['stage_id' => $academic->id, 'weight' => 60],
                ['stage_id' => $tahfidz->id, 'weight' => 30],
            ],
        ])->assertStatus(422);

        $this->assertStringContainsString('harus tepat 100', (string) $refused->json('errors.weights.0'));
        $this->assertSame(60.0, $this->num($this->stageRow($academic->id)['weight']), 'Bobot lama tidak berubah saat penyimpanan ditolak.');

        // Sebagian tahap saja ditolak: seluruh grup harus ikut terkirim.
        $this->putJson('/api/v1/ppdb/selection-stages/weights', [
            'period_id' => $this->period->id,
            'weights' => [['stage_id' => $academic->id, 'weight' => 100]],
        ])->assertStatus(422);

        // Pas 100% → tersimpan dan skor gabungan boleh dihitung.
        $synced = $this->putJson('/api/v1/ppdb/selection-stages/weights', [
            'period_id' => $this->period->id,
            'weights' => [
                ['stage_id' => $academic->id, 'weight' => 60],
                ['stage_id' => $tahfidz->id, 'weight' => 40],
            ],
        ])->assertOk()->assertJsonPath('data.summary.valid', true);

        // `json_encode` menaruh 100.0 sebagai int — bandingkan sebagai float.
        $this->assertSame(100.0, $this->num($synced->json('data.summary.total')));

        $this->assertSame(60.0, $this->num($this->stageRow($academic->id)['weight']));
        $this->assertSame(40.0, $this->num($this->stageRow($tahfidz->id)['weight']));

        $this->getJson('/api/v1/ppdb/selection-stages/weights?period_id='.$this->period->id)
            ->assertOk()
            ->assertJsonPath('data.summary.valid', true)
            ->assertJsonCount(2, 'data.stages');
    }

    public function test_skor_gabungan_menolak_dihitung_dari_bobot_yang_belum_seratus(): void
    {
        $parent = $this->registerParent('ibo@ppdb-alur.test', '081300000003');
        $applicant = $this->addApplicant($parent, 'Calin Skor', $this->male, '3301010101010103', '0123000003');
        $registration = $this->verifiedRegistration($parent, $applicant);

        $this->as($this->panitia);

        $academic = $this->stage('Akademik', PpdbSelectionStage::TYPE_ACADEMIC_TEST, 50.0);
        $interview = $this->stage('Wawancara', PpdbSelectionStage::TYPE_INTERVIEW, 25.0);

        $one = $this->enrolAndScore($academic, $registration, 80);
        $this->enrolAndScore($interview, $registration, 60);

        // Total 75%: skor gabungan tidak dinormalisasi diam-diam.
        $this->assertFalse($one['composite']['weight_valid']);
        $this->assertNotNull($one['composite']['weight_error']);
        $this->assertNull($one['composite']['score']);

        $this->putJson('/api/v1/ppdb/selection-stages/weights', [
            'period_id' => $this->period->id,
            'weights' => [
                ['stage_id' => $academic->id, 'weight' => 70],
                ['stage_id' => $interview->id, 'weight' => 30],
            ],
        ])->assertOk();

        $after = $this->participantRow($one['id']);

        // 80×70% + 60×30% = 74.
        $this->assertEqualsWithDelta(74.0, $this->num($after['composite']['score']), 0.01);
        $this->assertTrue($after['composite']['weight_valid']);
        $this->assertNull($after['composite']['weight_error']);
    }

    // =================================================================
    // PENJAGA KUOTA (keputusan: permission + alasan wajib)
    // =================================================================

    public function test_penetapan_lulus_di_atas_kuota_butuh_hak_dan_alasan(): void
    {
        $firstParent = $this->registerParent('quota-a@ppdb-alur.test', '081300000004');
        $secondParent = $this->registerParent('quota-b@ppdb-alur.test', '081300000005');

        $a = $this->verifiedRegistration($firstParent, $this->addApplicant($firstParent, 'Kuota A', $this->male, '3301010101010104', '0123000004'));
        $b = $this->verifiedRegistration($secondParent, $this->addApplicant($secondParent, 'Kuota B', $this->female, '3301010101010105', '0123000005'));

        // Kursi dipersempit lewat konfigurasi yang sah (bukan menulis database):
        // dua pendaftaran terverifikasi sekarang berebut satu kursi.
        $this->as($this->kepala)
            ->putJson("/api/v1/ppdb/programs/{$this->program->id}", ['quota' => 1])
            ->assertOk();

        $this->as($this->panitia);

        $this->postJson("/api/v1/ppdb/registrations/{$a}/result", ['result' => PpdbRegistration::STATUS_PASSED])
            ->assertOk()
            ->assertJsonPath('data.quota.over_quota', false);

        // Kursi habis: panitia biasa tidak boleh menambah kursi.
        $this->postJson("/api/v1/ppdb/registrations/{$b}/result", ['result' => PpdbRegistration::STATUS_PASSED])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quota');

        // Alasan saja tidak cukup — tetap butuh hak khusus.
        $this->postJson("/api/v1/ppdb/registrations/{$b}/result", [
            'result' => PpdbRegistration::STATUS_PASSED,
            'quota_override_reason' => 'Perintah kepala sekolah, kursi ditambah satu.',
        ])->assertForbidden();

        // Holders hak override: alasan wajib ada.
        $this->as($this->kepala)
            ->postJson("/api/v1/ppdb/registrations/{$b}/result", ['result' => PpdbRegistration::STATUS_PASSED])
            ->assertStatus(422);

        $overridden = $this->as($this->kepala)
            ->postJson("/api/v1/ppdb/registrations/{$b}/result", [
                'result' => PpdbRegistration::STATUS_PASSED,
                'quota_override_reason' => 'Tambahan kursi atas keputusan rapat pimpinan.',
            ])
            ->assertOk();

        $overridden->assertJsonPath('data.quota.over_quota', true)
            ->assertJsonPath('data.registration.status', PpdbRegistration::STATUS_PASSED);

        $this->assertDatabaseHas('ppdb_registrations', [
            'id' => $b,
            'quota_override_reason' => 'Tambahan kursi atas keputusan rapat pimpinan.',
        ]);

        $history = $this->as($this->kepala)
            ->getJson("/api/v1/ppdb/registrations/{$b}/history")
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty(array_filter(
            $history,
            static fn (array $row): bool => str_contains((string) $row['note'], 'MELEWATI KUOTA'),
        ), 'Alasan override harus terbaca pada riwayat pendaftaran.');

        // Alasan override juga tampil pada detail panitia ...
        $adminDetail = $this->getJson("/api/v1/ppdb/registrations/{$b}")
            ->assertOk()
            ->json();

        $this->assertTrue((bool) data_get($adminDetail, 'data.quota_override.applied'));
        $this->assertSame(
            'Tambahan kursi atas keputusan rapat pimpinan.',
            data_get($adminDetail, 'data.quota_override.reason'),
        );

        // ... dan tidak bocor ke portal pemilik pendaftaran.
        $this->as($secondParent)
            ->getJson("/api/v1/ppdb/portal/registrations/{$b}")
            ->assertOk()
            ->assertJsonMissingPath('data.quota_override');
    }

    /**
     * Promosi calon daftar tunggu tetap harus melewati penjaga kuota: kursi yang
     * sudah ditempati tidak bisa dilepas lewat HTTP, jadi menaikkan calon dari
     * daftar tunggu selalu menjadi keputusan kepemimpinan.
     */
    public function test_promosi_daftar_tunggu_tetap_lewat_penjaga_kuota(): void
    {
        $firstParent = $this->registerParent('tunggu-a@ppdb-alur.test', '081300000014');
        $secondParent = $this->registerParent('tunggu-b@ppdb-alur.test', '081300000015');

        $a = $this->verifiedRegistration($firstParent, $this->addApplicant($firstParent, 'Tunggu A', $this->male, '3301010101010114', '0123000014'));
        $b = $this->verifiedRegistration($secondParent, $this->addApplicant($secondParent, 'Tunggu B', $this->female, '3301010101010115', '0123000015'));

        $this->as($this->kepala)
            ->putJson("/api/v1/ppdb/programs/{$this->program->id}", ['quota' => 1])
            ->assertOk();

        $this->as($this->panitia);

        // Kursi tunggal terisi; calon kedua menunggu tanpa memakan kuota.
        $this->postJson("/api/v1/ppdb/registrations/{$a}/result", ['result' => PpdbRegistration::STATUS_PASSED])
            ->assertOk();

        $this->postJson("/api/v1/ppdb/registrations/{$b}/result", ['result' => PpdbRegistration::STATUS_WAITLISTED])
            ->assertOk()
            ->assertJsonPath('data.registration.status', PpdbRegistration::STATUS_WAITLISTED);

        // `waitlisted` bukan tahap seleksi, jadi promosi langsung ditolak; panitia
        // membuka kembali tahapnya lebih dulu (jalur yang diizinkan TRANSITIONS).
        $this->postJson("/api/v1/ppdb/registrations/{$b}/result", ['result' => PpdbRegistration::STATUS_PASSED])
            ->assertStatus(422);

        $this->postJson("/api/v1/ppdb/registrations/{$b}/transition", [
            'status' => PpdbRegistration::STATUS_SELECTION,
            'note' => 'Daftar tunggu dibuka kembali untuk promosi.',
        ])->assertOk();

        // Kursi masih dipakai calon pertama → promosi butuh hak + alasan.
        $this->postJson("/api/v1/ppdb/registrations/{$b}/result", ['result' => PpdbRegistration::STATUS_PASSED])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quota');

        $this->as($this->kepala)
            ->postJson("/api/v1/ppdb/registrations/{$b}/result", [
                'result' => PpdbRegistration::STATUS_PASSED,
                'quota_override_reason' => 'Kursi ditambah satu dan calon daftar tunggu dipromosikan.',
            ])
            ->assertOk()
            ->assertJsonPath('data.quota.over_quota', true)
            ->assertJsonPath('data.registration.status', PpdbRegistration::STATUS_PASSED);

        // Hasil baru terlihat pemiliknya di portal, dan hanya miliknya.
        $this->as($secondParent)
            ->getJson("/api/v1/ppdb/portal/registrations/{$b}/selection")
            ->assertOk()
            ->assertJsonPath('data.selection_result', PpdbRegistration::STATUS_PASSED);

        $this->as($firstParent)
            ->getJson("/api/v1/ppdb/portal/registrations/{$b}/selection")
            ->assertForbidden();
    }

    // =================================================================
    // SANGGAH (keputusan: tidak pernah mengubah hasil secara otomatis)
    // =================================================================

    public function test_sanggah_diterima_hanya_membuka_keputusan_panitia(): void
    {
        $parent = $this->registerParent('sanggah@ppdb-alur.test', '081300000006');
        $registration = $this->verifiedRegistration($parent, $this->addApplicant($parent, 'Calan Sanggah', $this->male, '3301010101010106', '0123000006'));

        $this->as($this->kepala)
            ->postJson("/api/v1/ppdb/registrations/{$registration}/result", ['result' => PpdbRegistration::STATUS_FAILED])
            ->assertOk();

        // Orang tua boleh menyanggah selama masa sanggah terbuka.
        $this->as($parent)
            ->getJson("/api/v1/ppdb/portal/registrations/{$registration}/appeals")
            ->assertOk()
            ->assertJsonPath('data.eligibility.can_appeal', true);

        $this->as($parent)
            ->postJson("/api/v1/ppdb/portal/registrations/{$registration}/appeals", [
                'reason' => 'Nilai tes akademik saya berbeda dengan yang ditampilkan panitia.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PpdbRegistrationAppeal::STATUS_PENDING);

        // Sanggah ganda ditolak.
        $this->as($parent)
            ->postJson("/api/v1/ppdb/portal/registrations/{$registration}/appeals", [
                'reason' => 'Saya ingin mengirim sanggahan kedua sebelum yang pertama putus.',
            ])
            ->assertStatus(422);

        // Catatan pemeriksaan wajib.
        $appeal = $this->as($this->panitia)->getJson('/api/v1/ppdb/appeals')->assertOk()->json('data.0.id');

        $this->postJson("/api/v1/ppdb/appeals/{$appeal}/decide", ['decision' => 'accepted'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('review_note');

        $decided = $this->postJson("/api/v1/ppdb/appeals/{$appeal}/decide", [
            'decision' => 'accepted',
            'review_note' => 'Berkas nilai ditinjau ulang, keputusan dibuka kembali.',
        ])->assertOk();

        // INIATURAN UTAMA: hasil seleksi tidak ikut berubah.
        $decided->assertJsonPath('data.result_changed', false)
            ->assertJsonPath('data.selection_result', PpdbRegistration::STATUS_FAILED)
            ->assertJsonPath('data.appeal.status', PpdbRegistrationAppeal::STATUS_ACCEPTED);

        $this->assertSame(PpdbRegistration::STATUS_FAILED, $this->registrationRow($registration)['selection_result']);

        // Yang dibuka adalah STATUS-nya (`failed → selection`) supaya panitia bisa
        // menilai ulang; hasilnya sendiri masih `failed` sampai ditetapkan ulang.
        $this->assertSame(PpdbRegistration::STATUS_SELECTION, $this->registrationRow($registration)['status']);

        $this->assertStringContainsString(
            'appeal.decided',
            implode(',', array_column($this->as($this->kepala)->getJson("/api/v1/ppdb/registrations/{$registration}/history")->assertOk()->json('data'), 'action')),
        );

        // Panitia kemudian menetapkan hasil baru secara eksplisit.
        $this->as($this->kepala)
            ->postJson("/api/v1/ppdb/registrations/{$registration}/result", [
                'result' => PpdbRegistration::STATUS_PASSED,
                'note' => 'Hasil diperbaiki setelah sanggahan diterima.',
            ])
            ->assertOk()
            ->assertJsonPath('data.registration.selection_result', PpdbRegistration::STATUS_PASSED);
    }

    public function test_masa_sanggah_tutup_menolak_sanggahan(): void
    {
        $parent = $this->registerParent('tutup@ppdb-alur.test', '081300000007');
        $registration = $this->verifiedRegistration($parent, $this->addApplicant($parent, 'Calan Tutup', $this->female, '3301010101010107', '0123000007'));

        $this->as($this->kepala)
            ->postJson("/api/v1/ppdb/registrations/{$registration}/result", ['result' => PpdbRegistration::STATUS_WAITLISTED])
            ->assertOk();

        $this->period->forceFill([
            'appeal_start' => now()->subMonths(2)->toDateString(),
            'appeal_end' => now()->subMonth()->toDateString(),
        ])->save();

        $refused = $this->as($parent)
            ->postJson("/api/v1/ppdb/portal/registrations/{$registration}/appeals", [
                'reason' => 'Saya mengajukan sanggahan walau masa sanggah sudah lewat.',
            ])
            ->assertStatus(422);

        $this->assertStringContainsString('Masa sanggah ditutup', (string) $refused->json('errors.reason.0'));
    }

    // =================================================================
    // ISOLASI DATA & KEWAJIBAN BAYAR
    // =================================================================

    public function test_orang_tua_hanya_melihat_pendaftaran_miliknya(): void
    {
        $mine = $this->registerParent('pemilik@ppdb-alur.test', '081300000008');
        $other = $this->registerParent('orang-lain@ppdb-alur.test', '081300000009');

        $ownRegistration = $this->createRegistration($mine, $this->addApplicant($mine, 'Anak Pemilik', $this->male, '3301010101010108', '0123000008'));
        $foreign = $this->createRegistration($other, $this->addApplicant($other, 'Anak Orang Lain', $this->female, '3301010101010109', '0123000009'));

        $this->as($mine);

        // Semua gerbang kepemilikan lewat `PpdbPortalAccess::registration()` —
        // pesan yang sama menandakan 403 berasal dari isolasi data, bukan dari
        // hak modul (yang akan berbunyi "tidak memiliki permission").
        foreach (['selection', 'appeals', 'payment'] as $path) {
            $this->getJson("/api/v1/ppdb/portal/registrations/{$foreign}/{$path}")
                ->assertForbidden()
                ->assertJsonPath('message', 'Pendaftaran tersebut bukan milik Anda.');
        }

        $this->postJson("/api/v1/ppdb/portal/registrations/{$foreign}/submit")->assertForbidden();

        // Halaman miliknya sendiri tetap terbaca.
        $this->getJson("/api/v1/ppdb/portal/registrations/{$ownRegistration}/selection")->assertOk();

        // Tanpa hak modul, sisi admin tertutup rapat.
        $this->as($other['user'])->getJson('/api/v1/ppdb/registrations')->assertForbidden();
        $this->getJson('/api/v1/ppdb/appeals')->assertForbidden();
        $this->as($this->panitia)->getJson('/api/v1/ppdb/registrations')->assertOk();
    }

    public function test_daftar_ulang_belum_selesai_selama_tagihan_belum_lunas(): void
    {
        $parent = $this->registerParent('belum@ppdb-alur.test', '081300000010');
        $registration = $this->verifiedRegistration($parent, $this->addApplicant($parent, 'Calan Belum', $this->male, '3301010101010110', '0123000010'));

        $this->as($this->kepala)
            ->postJson("/api/v1/ppdb/registrations/{$registration}/result", ['result' => PpdbRegistration::STATUS_PASSED])
            ->assertOk();

        $invoice = $this->as($this->panitia)
            ->postJson("/api/v1/ppdb/registrations/{$registration}/re-registration/open")
            ->assertOk()
            ->json('data.invoice.id');

        // Daftar ulang belum lunas → selesai ditolak, dan konversi belum terbuka.
        $this->postJson("/api/v1/ppdb/registrations/{$registration}/re-registration/complete")
            ->assertStatus(422)
            ->assertJsonValidationErrors('invoice');

        $notYet = $this->postJson("/api/v1/ppdb/registrations/{$registration}/convert-to-student")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertStringContainsString(
            'daftar ulang',
            (string) $notYet->json('errors.status.0'),
            'Konversi menolak dengan alasan yang menyebut daftar ulang.',
        );

        // Pembayaran sebagian belum melunasi. Nominal dibaca dari portal, bukan ditebak.
        $this->pay($parent, $invoice, round($this->reRegistrationOutstanding($parent, $registration) / 2));

        $this->as($this->panitia)
            ->postJson("/api/v1/ppdb/registrations/{$registration}/re-registration/complete")
            ->assertStatus(422);

        // Sisa tagihan dilunasi → daftar ulang boleh diselesaikan.
        $this->pay($parent, $invoice, $this->reRegistrationOutstanding($parent, $registration));

        $this->as($this->panitia)
            ->postJson("/api/v1/ppdb/registrations/{$registration}/re-registration/complete")
            ->assertOk()
            ->assertJsonPath('data.registration.status', PpdbRegistration::STATUS_COMPLETED);

        $this->assertSame(0.0, $this->reRegistrationOutstanding($parent, $registration));
    }

    // =================================================================
    // PENAGIHAN: SATU BIAYA SATU TAGIHAN
    // =================================================================

    public function test_biaya_yang_sudah_ditagih_tidak_muncul_di_tagihan_kedua(): void
    {
        $parent = $this->registerParent('tagih@ppdb-alur.test', '081300000013');
        $registration = $this->verifiedRegistration($parent, $this->addApplicant($parent, 'Calan Tagih', $this->female, '3301010101010113', '0123000013'));

        $invoices = $this->as($parent['user'])
            ->getJson("/api/v1/ppdb/portal/registrations/{$registration}/payment")
            ->assertOk()
            ->json('data.invoices');

        $this->assertCount(1, $invoices, 'Halaman pembayaran membuka satu tagihan biaya pendaftaran.');

        $first = $invoices[0];
        $this->assertSame(PpdbInvoice::TYPE_REGISTRATION, $first['type']);
        $this->assertSame(150000.0, $this->num($first['total_amount']));

        // Lunas, lalu halaman pembayaran dibuka lagi: piutang tidak boleh tumbuh.
        $this->pay($parent, (int) $first['id'], 150000);

        $again = $this->as($parent['user'])
            ->getJson("/api/v1/ppdb/portal/registrations/{$registration}/payment")
            ->assertOk()
            ->json('data.invoices');

        $this->assertCount(1, $again, 'Tagihan yang sudah lunas dipakai ulang, bukan dibuat baru.');
        $this->assertSame($first['id'], $again[0]['id']);
        $this->assertSame(PpdbInvoice::STATUS_PAID, $again[0]['status']);
        $this->assertSame(0.0, $this->num($again[0]['outstanding_amount']));

        // Biaya yang sudah tertagih tidak ikut menumpang di tagihan daftar ulang.
        $this->as($this->kepala)
            ->postJson("/api/v1/ppdb/registrations/{$registration}/result", ['result' => PpdbRegistration::STATUS_PASSED])
            ->assertOk();

        $opened = $this->as($this->panitia)
            ->postJson("/api/v1/ppdb/registrations/{$registration}/re-registration/open")
            ->assertOk();

        $this->assertSame(250000.0, $this->num($opened->json('data.invoice.total_amount')));
        $this->assertSame(
            ['Biaya Daftar Ulang'],
            array_column((array) $opened->json('data.invoice.items'), 'name'),
        );
        $this->assertNotSame($first['number'], $opened->json('data.invoice.number'));
    }

    // =================================================================
    // HALAMAN PUBLIK: KUOTA TERISI MENCERMINKAN PENDAFTAR NYATA
    // =================================================================

    public function test_halaman_publik_menampilkan_sisa_kuota_sebenarnya(): void
    {
        $parent = $this->registerParent('publik@ppdb-alur.test', '081300000011');
        $applicant = $this->addApplicant($parent, 'Calan Publik', $this->female, '3301010101010111', '0123000011');

        $registration = $this->createRegistration($parent, $applicant, $this->tight);

        $show = $this->getJson('/api/v1/ppdb/public/'.$this->period->slug)->assertOk();

        $this->assertSame(1, $show->json('data.quota.registered'));
        $this->assertSame(1, $show->json('data.waves.0.registered'));

        $tightProgram = collect($show->json('data.programs'))
            ->firstWhere('code', 'KETAT');

        $this->assertSame(1, $tightProgram['registered']);
        $this->assertSame(0, $tightProgram['remaining']);
        $this->assertTrue($tightProgram['is_full']);

        $regular = collect($show->json('data.programs'))->firstWhere('code', 'REG');
        $this->assertSame(0, $regular['registered']);
        $this->assertSame(5, $regular['remaining']);
        $this->assertFalse($regular['is_full']);

        // Pendaftar yang mengundurkan diri tidak menyita kursi publik. Pembatalan
        // lewat endpoint panitia (`POST /registrations/{id}/cancel`), bukan menulis DB.
        $this->as($this->panitia)
            ->postJson("/api/v1/ppdb/registrations/{$registration}/cancel", [
                'note' => 'Orang tua membatalkan pendaftaran.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', PpdbRegistration::STATUS_CANCELLED);

        $after = $this->getJson('/api/v1/ppdb/public/'.$this->period->slug)->assertOk();
        $this->assertSame(0, collect($after->json('data.programs'))->firstWhere('code', 'KETAT')['registered']);
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
            'address' => 'Jl. Alur Uji No. 1, Bandung',
        ])->assertCreated();

        $this->assertNotEmpty($created->json('data.token'), 'Pendaftaran mandiri mengembalikan token Sanctum.');

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
                'previous_school' => 'SMP Alur Uji',
                'address' => 'Jl. Alur Uji No. 1',
                'target_program_id' => $this->program->id,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    private function createRegistration(array $parent, int $applicantId, ?PpdbProgram $program = null): int
    {
        return (int) $this->as($parent['user'])
            ->postJson('/api/v1/ppdb/portal/registrations', [
                'applicant_id' => $applicantId,
                'period_id' => $this->period->id,
                'program_id' => ($program ?? $this->program)->id,
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

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function documentsOf(int $registrationId): \Illuminate\Support\Collection
    {
        $this->as($this->panitia);

        $rows = $this->getJson('/api/v1/ppdb/documents?registration_id='.$registrationId)
            ->assertOk()
            ->json('data');

        return collect((array) $rows)->filter(
            static fn (array $row): bool => ! isset($row['registration_id']) || (int) $row['registration_id'] === $registrationId
        )->values();
    }

    /** Draft → submitted → berkas disetujui → verified, semuanya lewat HTTP. */
    private function verifiedRegistration(array $parent, int $applicantId, ?PpdbProgram $program = null): int
    {
        $registration = $this->createRegistration($parent, $applicantId, $program);

        $this->uploadDocument($parent, $registration);

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

    /** Bayar lewat portal lalu verifikasi oleh bendahara (jalur yang sama untuk pembayaran sebagian). */
    private function pay(array $parent, int $invoiceId, float $amount): void
    {
        $payment = (int) $this->as($parent['user'])
            ->postJson("/api/v1/ppdb/portal/invoices/{$invoiceId}/pay", [
                'amount' => $amount,
                'method' => 'manual',
                'payment_method_id' => $this->transfer->id,
                'reference' => 'JB-'.uniqid(),
                'proof' => UploadedFile::fake()->create('bukti.jpg', 90, 'image/jpeg'),
            ])
            ->assertSuccessful()
            ->json('data.payment.id');

        $this->as($this->bendahara)
            ->postJson("/api/v1/ppdb/payments/{$payment}/verify", ['note' => 'Uang sudah masuk rekening.'])
            ->assertOk();
    }

    /** Sisa tagihan daftar ulang, dibaca orang tua lewat portal. */
    private function reRegistrationOutstanding(array $parent, int $registration): float
    {
        $invoices = $this->as($parent['user'])
            ->getJson("/api/v1/ppdb/portal/registrations/{$registration}/payment")
            ->assertOk()
            ->json('data.invoices');

        $invoice = collect((array) $invoices)->firstWhere('type', PpdbInvoice::TYPE_RE_REGISTRATION);

        $this->assertNotNull($invoice, 'Tagihan daftar ulang tersedia di portal orang tua.');

        return round((float) $invoice['outstanding_amount'], 2);
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

    /** @return array<string, mixed> */
    private function stageRow(int $stageId): array
    {
        return collect((array) $this->as($this->panitia)
            ->getJson('/api/v1/ppdb/selection-stages?period_id='.$this->period->id.'&per_page=200')
            ->assertOk()
            ->json('data.stages'))
            ->firstWhere('id', $stageId) ?? [];
    }

    private function publicRegisteredCount(): int
    {
        return (int) $this->getJson('/api/v1/ppdb/public/'.$this->period->slug)->assertOk()->json('data.quota.registered');
    }

    private function num(mixed $value): float
    {
        return (float) $value;
    }
}
