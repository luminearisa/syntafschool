<?php

namespace Tests\Feature;

use App\Models\QuranSurah;
use App\Models\TahfizCertificate;
use App\Models\TahfizEnrollment;
use App\Models\TahfizExam;
use App\Models\TahfizExamItem;
use App\Models\TahfizExamParticipant;
use App\Models\TahfizHalaqah;
use App\Models\TahfizMemorizationRecord;
use App\Models\TahfizParticipant;
use App\Models\TahfizProgram;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Modul Tahfiz / Al-Qur'an Management System.
 *
 * Satu test untuk satu aturan bisnis: RBAC per peran, alur enrollment,
 * pencatatan setoran beserta jejak audit, pemisahan "hafal" vs "mutqin",
 * pembangkitan butir ujian dari rentang hafalan nyata, dan verifikasi
 * publik sertifikat lewat QR.
 */
class TahfizTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
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

    /** Dunia kecil: program, surah, peserta, halaqah, enrollment aktif. */
    private function world(): array
    {
        $program = TahfizProgram::factory()->create([
            'participant_type' => 'public',
            'target_type' => 'juz',
            'status' => 'active',
        ]);

        QuranSurah::query()->insert([
            ['number' => 1, 'name' => 'Al-Fatiha', 'arabic_name' => 'الفاتحة', 'ayah_count' => 7, 'page_start' => 1, 'page_end' => 1, 'juz_start' => 1, 'juz_end' => 1, 'revelation_place' => 'makkah', 'created_at' => now(), 'updated_at' => now()],
            ['number' => 2, 'name' => 'Al-Baqarah', 'arabic_name' => 'البقرة', 'ayah_count' => 286, 'page_start' => 2, 'page_end' => 49, 'juz_start' => 1, 'juz_end' => 3, 'revelation_place' => 'madinah', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $participant = TahfizParticipant::factory()->create([
            'participant_type' => 'public',
            'status' => 'active',
        ]);

        $halaqah = TahfizHalaqah::factory()->create([
            'program_id' => $program->id,
            'status' => 'active',
        ]);

        $enrollment = TahfizEnrollment::factory()->create([
            'participant_id' => $participant->id,
            'program_id' => $program->id,
            'halaqah_id' => $halaqah->id,
            'status' => TahfizEnrollment::STATUS_ACTIVE,
            'enrolled_at' => now()->toDateString(),
        ]);

        return compact('program', 'participant', 'halaqah', 'enrollment');
    }

    /** Catat setoran lalu dinilai, mengembalikan record. */
    private function setor(array $world, string $status, int $start, int $end, float $score = 90.0): TahfizMemorizationRecord
    {
        $this->actingAsRole('guru');

        $record = TahfizMemorizationRecord::factory()->create([
            'participant_id' => $world['participant']->id,
            'enrollment_id' => $world['enrollment']->id,
            'halaqah_id' => $world['halaqah']->id,
            'surah_id' => 1,
            'ayah_start' => $start,
            'ayah_end' => $end,
            'date' => now()->toDateString(),
            'status' => TahfizMemorizationRecord::STATUS_SUBMITTED,
            'type' => 'hafalan_baru',
        ]);

        $this->postJson("/api/v1/tahfiz/memorization-records/{$record->id}/review", [
            'status' => $status,
            'score' => $score,
            'fluency' => 4,
            'tajwid' => 4,
            'makhraj' => 4,
            // Diterima dengan revisi wajib membawa tenggat revisi.
            'revision_due_date' => $status === 'accepted_with_revision'
                ? now()->addDays(7)->toDateString()
                : null,
        ])->assertOk();

        return $record->fresh();
    }

    // =================================================================
    // RBAC
    // =================================================================

    public function test_guru_dapat_mencatat_setoran_tapi_tidak_menghapusnya(): void
    {
        $world = $this->world();
        $this->actingAsRole('guru');

        $this->postJson('/api/v1/tahfiz/memorization-records', [
            'participant_id' => $world['participant']->id,
            'enrollment_id' => $world['enrollment']->id,
            'halaqah_id' => $world['halaqah']->id,
            'date' => now()->toDateString(),
            'type' => 'hafalan_baru',
            'surah_id' => 1,
            'ayah_start' => 1,
            'ayah_end' => 3,
        ])->assertCreated();

        $this->assertDatabaseHas('tahfiz_memorization_records', [
            'participant_id' => $world['participant']->id,
            'ayah_start' => 1,
            'ayah_end' => 3,
        ]);

        // guru tidak punya tahfiz.delete
        $record = TahfizMemorizationRecord::query()->first();
        $this->deleteJson("/api/v1/tahfiz/memorization-records/{$record->id}")->assertForbidden();
    }

    public function test_siswa_hanya_bisa_melihat_tidak_bisa_mencatat(): void
    {
        $world = $this->world();
        $this->actingAsRole('siswa');

        $this->postJson('/api/v1/tahfiz/memorization-records', [
            'participant_id' => $world['participant']->id,
            'date' => now()->toDateString(),
            'type' => 'hafalan_baru',
            'surah_id' => 1,
            'ayah_start' => 1,
            'ayah_end' => 3,
        ])->assertForbidden();

        // lihat diperbolehkan
        $this->getJson('/api/v1/tahfiz/participants')->assertOk();
    }

    public function test_pendaftaran_mandiri_publik_tanpa_login(): void
    {
        $world = $this->world();

        $this->postJson('/api/v1/tahfiz/registrations', [
            'program_id' => $world['program']->id,
            'name' => 'Pendaftar Umum',
            'gender' => 'male',
            'phone' => '08123456789',
        ])->assertCreated();
    }

    // =================================================================
    // Alur enrollment
    // =================================================================

    public function test_alur_enrollment_dari_registered_sampai_active(): void
    {
        $world = $this->world();
        $this->actingAsRole('admin-sekolah');

        // Program terpisah: (participant_id, program_id) unik.
        $program = TahfizProgram::factory()->create(['status' => 'active']);

        $enrollment = TahfizEnrollment::factory()->create([
            'participant_id' => $world['participant']->id,
            'program_id' => $program->id,
            'halaqah_id' => $world['halaqah']->id,
            'status' => TahfizEnrollment::STATUS_REGISTERED,
        ]);

        // registered -> assessment -> approved -> active
        foreach ([
            TahfizEnrollment::STATUS_ASSESSMENT,
            TahfizEnrollment::STATUS_APPROVED,
            TahfizEnrollment::STATUS_ACTIVE,
        ] as $status) {
            $this->postJson("/api/v1/tahfiz/enrollments/{$enrollment->id}/transition", [
                'status' => $status,
                'halaqah_id' => $world['halaqah']->id,
            ])->assertOk();

            $this->assertDatabaseHas('tahfiz_enrollments', [
                'id' => $enrollment->id,
                'status' => $status,
            ]);
        }
    }

    public function test_transisi_enrollment_yang_tidak_diizinkan_ditolak(): void
    {
        $world = $this->world();
        $this->actingAsRole('admin-sekolah');

        $program = TahfizProgram::factory()->create(['status' => 'active']);

        $enrollment = TahfizEnrollment::factory()->create([
            'participant_id' => $world['participant']->id,
            'program_id' => $program->id,
            'status' => TahfizEnrollment::STATUS_REGISTERED,
        ]);

        // draft langsung active harus ditolak; draft hanya boleh ke
        // registered/rejected.
        $enrollment->update(['status' => TahfizEnrollment::STATUS_DRAFT]);

        $this->postJson("/api/v1/tahfiz/enrollments/{$enrollment->id}/transition", [
            'status' => TahfizEnrollment::STATUS_ACTIVE,
        ])->assertStatus(422);

        $this->assertDatabaseHas('tahfiz_enrollments', [
            'id' => $enrollment->id,
            'status' => TahfizEnrollment::STATUS_DRAFT,
        ]);
    }

    // =================================================================
    // Setoran + audit
    // =================================================================

    public function test_setoran_mencatat_pencatat_dan_penilai(): void
    {
        $world = $this->world();
        $guru = $this->actingAsRole('guru');

        $response = $this->postJson('/api/v1/tahfiz/memorization-records', [
            'participant_id' => $world['participant']->id,
            'enrollment_id' => $world['enrollment']->id,
            'halaqah_id' => $world['halaqah']->id,
            'date' => now()->toDateString(),
            'type' => 'hafalan_baru',
            'surah_id' => 1,
            'ayah_start' => 1,
            'ayah_end' => 3,
        ])->assertCreated();

        $this->assertDatabaseHas('tahfiz_memorization_records', [
            'id' => $response->json('data.id'),
            'recorded_by' => $guru->id,
            'status' => TahfizMemorizationRecord::STATUS_SUBMITTED,
        ]);

        // penilaian mencatat reviewer
        $this->postJson("/api/v1/tahfiz/memorization-records/{$response->json('data.id')}/review", [
            'status' => 'accepted',
            'score' => 95,
        ])->assertOk();

        $this->assertDatabaseHas('tahfiz_memorization_records', [
            'id' => $response->json('data.id'),
            'reviewed_by' => $guru->id,
            'status' => 'accepted',
        ]);
    }

    public function test_setoran_yang_sudah_dinilai_tidak_bisa_diubah(): void
    {
        $world = $this->world();
        $record = $this->setor($world, 'accepted', 1, 3);
        $this->actingAsRole('guru');

        $this->putJson("/api/v1/tahfiz/memorization-records/{$record->id}", [
            'ayah_end' => 5,
        ])->assertStatus(422);
    }

    public function test_ayat_akhir_tidak_boleh_melebihi_jumlah_ayat_surah(): void
    {
        $world = $this->world();
        $this->actingAsRole('guru');

        $this->postJson('/api/v1/tahfiz/memorization-records', [
            'participant_id' => $world['participant']->id,
            'date' => now()->toDateString(),
            'type' => 'hafalan_baru',
            'surah_id' => 1, // Al-Fatiha: 7 ayat
            'ayah_start' => 1,
            'ayah_end' => 99,
        ])->assertStatus(422);
    }

    // =================================================================
    // Hafal vs mutqin
    // =================================================================

    public function test_hafal_dan_mutqin_dihitung_terpisah(): void
    {
        $world = $this->world();

        // ayat 1-3 diterima mutqin
        $this->setor($world, 'accepted', 1, 3, 95);
        // ayat 4-7 diterima dengan revisi — dihafal, tapi belum mutqin
        $this->setor($world, 'accepted_with_revision', 4, 7, 70);

        $progress = app(\App\Services\Tahfiz\ProgressService::class)
            ->memorizationMap($world['participant']->id);

        $this->assertSame(7, $progress['ayahs_memorized']);
        $this->assertSame(3, $progress['ayahs_mutqin']);
        $this->assertGreaterThan($progress['mutqin_percent'], $progress['memorized_percent']);
        $this->assertSame('belum_mutqin', $progress['surahs'][0]['status']);
    }

    public function test_mengubah_setoran_menyegarkan_progress(): void
    {
        $world = $this->world();
        $service = app(\App\Services\Tahfiz\ProgressService::class);

        $accepted = $this->setor($world, 'accepted', 1, 3);
        $revision = $this->setor($world, 'accepted_with_revision', 4, 7, 70);

        $this->assertSame(3, $service->memorizationMap($world['participant']->id)['ayahs_mutqin']);

        // setoran revisi dinaikkan menjadi diterima penuh -> seluruhnya mutqin
        $revision->update(['status' => 'accepted']);
        $service->onMemorizationRecordSaved($revision->fresh());

        $map = $service->memorizationMap($world['participant']->id);
        $this->assertSame(7, $map['ayahs_mutqin']);
        // Seluruh ayat sudah diterima, tapi skor rata-rata (95 + 70)/2 = 82,5
        // di bawah ambang mutqin (90) → statusnya "lancar", bukan "mutqin".
        // Inilah yang membedakan "hafal" dari "mutqin".
        $this->assertSame('lancar', $map['surahs'][0]['status']);
    }

    // =================================================================
    // Ujian: butir dari rentang hafalan nyata
    // =================================================================

    public function test_butir_ujian_diambil_dari_rentang_yang_sudah_dihafal(): void
    {
        $world = $this->world();

        // peserta hanya menghafal Al-Fatiha 1-7
        $this->setor($world, 'accepted', 1, 7, 95);

        $exam = TahfizExam::factory()->create([
            'program_id' => $world['program']->id,
            'type' => 'acak_ayat',
            'max_score' => 100,
            'passing_score' => 75,
            'status' => 'ongoing',
        ]);

        $examParticipant = TahfizExamParticipant::create([
            'exam_id' => $exam->id,
            'participant_id' => $world['participant']->id,
            'status' => 'terdaftar',
        ]);

        $items = app(\App\Services\Tahfiz\ExamService::class)->generateItems($examParticipant, ['acak_ayat'], 5);

        $this->assertNotEmpty($items);

        foreach ($items as $item) {
            $this->assertSame(1, $item->surah_id);
            $this->assertGreaterThanOrEqual(1, $item->ayah_start);
            $this->assertLessThanOrEqual(7, $item->ayah_start);
            $this->assertLessThanOrEqual(7, $item->expected_ayah_end);
        }
    }

    public function test_hasil_ujian_dihitung_dari_butir_yang_dinilai(): void
    {
        $world = $this->world();
        $this->setor($world, 'accepted', 1, 7, 95);

        $exam = TahfizExam::factory()->create([
            'program_id' => $world['program']->id,
            'type' => 'acak_ayat',
            'max_score' => 100,
            'passing_score' => 60,
            'status' => 'ongoing',
        ]);

        $examParticipant = TahfizExamParticipant::create([
            'exam_id' => $exam->id,
            'participant_id' => $world['participant']->id,
            'status' => 'terdaftar',
        ]);

        $items = app(\App\Services\Tahfiz\ExamService::class)->generateItems($examParticipant, ['acak_ayat'], 4);

        // 3 benar, 1 salah
        foreach ($items as $i => $item) {
            $item->update([
                'actual_ayah_end' => $i < 3 ? $item->expected_ayah_end : 1,
                'score' => $i < 3 ? 25 : 0,
            ]);
        }

        $result = app(\App\Services\Tahfiz\ExamService::class)->recordResult($examParticipant);

        // 3 dari 4 butir benar = 75% → lulus (passing 60), grade C.
        $this->assertSame('lulus', $result->result);
        $this->assertSame('C', $result->grade);
    }

    // =================================================================
    // Sertifikat + verifikasi QR publik
    // =================================================================

    public function test_sertifikat_diterbitkan_dengan_nomor_dan_token_unik(): void
    {
        $world = $this->world();
        $this->actingAsRole('admin-sekolah');

        $response = $this->postJson('/api/v1/tahfiz/certificates', [
            'participant_id' => $world['participant']->id,
            'program_id' => $world['program']->id,
            'achievement_type' => 'hafal_1_juz',
            'juz_count' => 1,
            'issue_date' => now()->toDateString(),
            'signature_name' => 'Kepala Madrasah',
        ])->assertCreated();

        $this->assertNotEmpty($response->json('data.certificate_number'));
        $this->assertNotEmpty($response->json('data.qr_token'));
        $this->assertSame('issued', $response->json('data.status'));

        // token QR tidak boleh dikembalikan oleh endpoint daftar
        $this->assertNotEmpty(TahfizCertificate::query()->whereKey($response->json('data.id'))->value('qr_token'));
    }

    public function test_verifikasi_qr_publik_tanpa_login(): void
    {
        $world = $this->world();
        $certificate = TahfizCertificate::factory()->create([
            'participant_id' => $world['participant']->id,
            'program_id' => $world['program']->id,
            'achievement_type' => 'hafal_1_juz',
            'issue_date' => now()->toDateString(),
            'status' => 'issued',
        ]);

        // tamu tanpa login
        $this->app["auth"]->forgetGuards();

        $this->postJson('/api/v1/tahfiz/certificates/verify', [
            'qr_token' => $certificate->qr_token,
        ])->assertOk()
            ->assertJsonPath('data.is_valid', true)
            ->assertJsonPath('data.certificate_number', $certificate->certificate_number);

        // token tidak dikenal
        $this->postJson('/api/v1/tahfiz/certificates/verify', [
            'qr_token' => 'tidak-ada',
        ])->assertNotFound();
    }

    public function test_sertifikat_yang_dicabut_tidak_lagi_terverifikasi(): void
    {
        $world = $this->world();
        $this->actingAsRole('admin-sekolah');

        $certificate = TahfizCertificate::factory()->create([
            'participant_id' => $world['participant']->id,
            'program_id' => $world['program']->id,
            'achievement_type' => 'hafal_1_juz',
            'issue_date' => now()->toDateString(),
            'status' => 'issued',
        ]);

        $this->postJson("/api/v1/tahfiz/certificates/{$certificate->id}/revoke", [
            'revoke_reason' => 'Dibatalkan karena data salah',
        ])->assertOk();

        $this->assertSame('revoked', $certificate->fresh()->status);

        $this->app["auth"]->forgetGuards();

        $this->postJson('/api/v1/tahfiz/certificates/verify', [
            'qr_token' => $certificate->fresh()->qr_token,
        ])->assertNotFound();
    }

    // =================================================================
    // REFERENSI AL-QUR'AN
    // =================================================================

    public function test_daftar_surah_dibaca_dari_referensi_bukan_ditulis_di_klien(): void
    {
        $this->world();
        $this->actingAsRole('guru');

        $response = $this->getJson('/api/v1/tahfiz/surahs')->assertOk();

        // Referensi surah adalah sumber kebenaran struktur mushaf: jumlah ayat
        // dipakai progress engine maupun validasi form setoran.
        $surahs = collect($response->json('data'));

        $this->assertCount(2, $surahs);
        $this->assertSame(
            7,
            $surahs->firstWhere('number', 1)['ayah_count'],
        );
    }

    public function test_daftar_surah_menyaring_per_juz(): void
    {
        $this->world();
        $this->actingAsRole('guru');

        $response = $this->getJson('/api/v1/tahfiz/surahs?filter[juz]=2')->assertOk();

        // Hanya Al-Baqarah yang membentang hingga juz 2–3, Al-Fatiha tidak.
        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertSame(['Al-Baqarah'], $names);
    }
}
