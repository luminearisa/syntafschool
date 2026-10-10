<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\AssessmentWeight;
use App\Models\ClassStudent;
use App\Models\ClassSubject;
use App\Models\Employee;
use App\Models\GradeScale;
use App\Models\GradeScaleItem;
use App\Models\GradingFormula;
use App\Models\PassingGradeRule;
use App\Models\Remedial;
use App\Models\SchoolClass;
use App\Models\Score;
use App\Models\ScoreRevision;
use App\Models\Semester;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A7 — Penilaian (Assessment) & Grading Engine.
 *
 * Satu test untuk satu aturan bisnis: validasi konteks penilaian, input skor
 * satuan & massal, jejak revisi, alur draft → submitted → verified → finalized,
 * penguncian nilai, perhitungan berbobot dengan normalisasi persen, KKTP, dan
 * aturan remedial (max / average / cap).
 */
class AssessmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    // =================================================================
    // Penilaian
    // =================================================================

    public function test_guru_dapat_membuat_penilaian_dengan_guru_pengampu_sebagai_default(): void
    {
        $world = $this->world();
        $type = $this->makeType($world);
        $this->actingAsRole('guru');

        $response = $this->postJson('/api/v1/assessments', [
            'class_id' => $world['class']->id,
            'subject_id' => $world['subject']->id,
            'semester_id' => $world['semester']->id,
            'assessment_type_id' => $type->id,
            'name' => 'Tugas Bab 1',
            'description' => 'Kerjakan soal 1 sampai 10.',
            'max_score' => 100,
            'date' => '2026-09-10',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', Assessment::STATUS_DRAFT)
            ->assertJsonPath('data.class_id', $world['class']->id)
            ->assertJsonPath('data.teacher_id', $world['teacher']->id);

        $this->assertFloat(100.0, $response->json('data.max_score'));
    }

    public function test_status_nilai_tidak_bisa_diubah_lewat_form_penilaian(): void
    {
        $world = $this->world();
        $type = $this->makeType($world);
        $this->actingAsRole('admin-sekolah');

        // `status` sengaja bukan field form: transisinya punya endpoint sendiri
        // supaya selalu lewat pemeriksaan aturan.
        $this->postJson('/api/v1/assessments', $this->assessmentPayload($world, $type, [
            'status' => Assessment::STATUS_FINALIZED,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.status', Assessment::STATUS_DRAFT);
    }

    public function test_mata_pelajaran_harus_diampu_di_kelas_tersebut(): void
    {
        $world = $this->world();
        $type = $this->makeType($world);
        $this->actingAsRole('guru');

        $response = $this->postJson('/api/v1/assessments', $this->assessmentPayload($world, $type, [
            'subject_id' => $this->makeSubject('MPL-Z', 'Bahasa Jepang')->id,
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors('subject_id');
        $this->assertErrorContains($response, 'subject_id', 'belum diampu di kelas');
    }

    public function test_tanggal_penilaian_harus_berada_di_dalam_rentang_semester(): void
    {
        $world = $this->world();
        $type = $this->makeType($world);
        $this->actingAsRole('guru');

        $response = $this->postJson('/api/v1/assessments', $this->assessmentPayload($world, $type, [
            'date' => '2027-02-01',
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->assertErrorContains($response, 'date', 'di dalam rentang semester');
    }

    public function test_guru_penilai_harus_pegawai_berstatus_pengajar(): void
    {
        $world = $this->world();
        $type = $this->makeType($world);
        $staff = Employee::create([
            'full_name' => 'Ani Staf Tata Usaha',
            'employee_type' => 'staf',
            'employment_status' => 'PNS',
            'is_teacher' => false,
            'is_active' => true,
        ]);
        $this->actingAsRole('guru');

        $this->postJson('/api/v1/assessments', $this->assessmentPayload($world, $type, [
            'teacher_id' => $staff->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('teacher_id');
    }

    public function test_konteks_penilaian_tidak_berubah_setelah_nilai_tercatat(): void
    {
        $world = $this->world();
        $type = $this->makeType($world);
        $assessment = $this->makeAssessment($world, ['assessment_type_id' => $type->id]);
        $this->actingAsRole('guru');

        $this->postJson($this->scoreRoute($assessment), [
            'student_id' => $world['students'][0]->id,
            'score' => 80,
        ])->assertCreated();

        $response = $this->putJson("/api/v1/assessments/{$assessment->id}", $this->assessmentPayload($world, $type, [
            'subject_id' => $this->makeSubject('MPL-Y', 'Seni Musik')->id,
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors('subject_id');
        $this->assertErrorContains($response, 'subject_id', 'nilai siswa sudah tercatat');

        $this->assertSame($world['subject']->id, $assessment->fresh()->subject_id);
    }

    // =================================================================
    // Input skor
    // =================================================================

    public function test_input_skor_mengisi_huruf_predikat_dan_jejak_revisi(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);
        $this->actingAsRole('guru');

        $this->postJson($this->scoreRoute($assessment), [
            'student_id' => $world['students'][0]->id,
            'score' => 92,
            'notes' => 'Presentasi rapi.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.grade', 'A')
            ->assertJsonPath('data.predicate', 'Sangat Baik');

        $score = Score::query()->sole();
        $this->assertSame(92.0, (float) $score->score);

        $revision = ScoreRevision::query()->where('score_id', $score->id)->sole();
        $this->assertNull($revision->old_score);
        $this->assertSame(92.0, (float) $revision->new_score);
        $this->assertSame('Input nilai awal', $revision->reason);
    }

    public function test_simpan_ulang_skor_yang_sama_memperbarui_baris_bukan_menggandakannya(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);
        $this->actingAsRole('guru');

        $payload = ['student_id' => $world['students'][0]->id, 'score' => 70];

        $this->postJson($this->scoreRoute($assessment), $payload)->assertCreated();
        $this->postJson($this->scoreRoute($assessment), $payload)
            ->assertOk()
            ->assertJsonPath('data.grade', 'C');

        $this->assertSame(1, Score::query()->count());
        // Nilainya tidak berubah, jadi tidak boleh ada revisi kedua.
        $this->assertSame(1, ScoreRevision::query()->count());
    }

    public function test_skor_ditolak_bila_melebihi_nilai_maksimum_penilaian(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world); // max_score 100
        $this->actingAsRole('guru');

        $response = $this->postJson($this->scoreRoute($assessment), [
            'student_id' => $world['students'][0]->id,
            'score' => 140,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('score');
        $this->assertErrorContains($response, 'score', 'di antara 0 dan 100');
        $this->assertSame(0, Score::query()->count());
    }

    public function test_skor_hanya_bisa_dicatat_untuk_siswa_anggota_kelas(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);
        $outsider = $this->makeStudent('NIS-Z9', 'Zaki Anak Kelas Lain');
        $this->actingAsRole('guru');

        $response = $this->postJson($this->scoreRoute($assessment), [
            'student_id' => $outsider->id,
            'score' => 80,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('student_id');
        $this->assertErrorContains($response, 'student_id', 'bukan anggota aktif kelas');
        $this->assertSame(0, Score::query()->count());
    }

    public function test_input_massal_menyimpan_seluruh_baris(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);
        $this->actingAsRole('guru');

        $this->postJson($this->scoreRoute($assessment).'/bulk', [
            'entries' => [
                ['student_id' => $world['students'][0]->id, 'score' => 88],
                ['student_id' => $world['students'][1]->id, 'score' => 65, 'notes' => 'Perlu pendampingan.'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.total', 2);

        $this->assertSame(2, Score::query()->count());
    }

    public function test_input_massal_dibatalkan_seluruhnya_bila_ada_satu_baris_salah(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);
        $outsider = $this->makeStudent('NIS-Z8', 'Zaki Anak Kelas Lain');
        $this->actingAsRole('guru');

        $this->postJson($this->scoreRoute($assessment).'/bulk', [
            'entries' => [
                ['student_id' => $world['students'][0]->id, 'score' => 88],
                ['student_id' => $outsider->id, 'score' => 70],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['entries.1.student_id']);

        // Baris yang sah tidak ikut tersimpan: guru tidak berakhir dengan nilai separuh jadi.
        $this->assertSame(0, Score::query()->count());
        $this->assertSame(0, ScoreRevision::query()->count());
    }

    public function test_input_massal_mendeteksi_skor_di_atas_maksimum_sebelum_menulis(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world, ['max_score' => 40]);
        $this->actingAsRole('guru');

        $this->postJson($this->scoreRoute($assessment).'/bulk', [
            'entries' => [
                ['student_id' => $world['students'][0]->id, 'score' => 32],
                ['student_id' => $world['students'][1]->id, 'score' => 90],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['entries.1.score']);

        $this->assertSame(0, Score::query()->count());
    }

    public function test_perubahan_skor_setelah_diserahkan_wajib_menyertakan_alasan(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);
        $this->actingAsRole('guru');
        $this->gradeAll($assessment, $world);

        $this->postJson("/api/v1/assessments/{$assessment->id}/submit")->assertOk();

        $response = $this->postJson($this->scoreRoute($assessment), [
            'student_id' => $world['students'][0]->id,
            'score' => 75,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertErrorContains($response, 'reason', 'Alasan wajib diisi');

        $this->postJson($this->scoreRoute($assessment), [
            'student_id' => $world['students'][0]->id,
            'score' => 75,
            'reason' => 'Koreksi penjumlahan skor nomor 4.',
        ])->assertOk();

        $this->assertSame(3, ScoreRevision::query()->count());
        $this->assertSame(
            'Koreksi penjumlahan skor nomor 4.',
            ScoreRevision::query()->latest('id')->firstOrFail()->reason,
        );
    }

    public function test_penghapusan_skor_juga_meninggalkan_jejak_revisi(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);
        $this->actingAsRole('guru');

        $this->postJson($this->scoreRoute($assessment), [
            'student_id' => $world['students'][0]->id,
            'score' => 80,
        ])->assertCreated();

        $score = Score::query()->sole();

        $this->deleteJson($this->scoreRoute($assessment)."/{$score->id}", ['reason' => 'Siswa pindah kelas.'])
            ->assertOk();

        $this->assertSame(0, Score::query()->count());

        // Barisnya hilang dari daftar nilai, tapi jejak input + penghapusannya tetap ada.
        $this->assertSame(2, ScoreRevision::query()->count());

        $revision = ScoreRevision::query()->where('score_id', $score->id)->latest('id')->firstOrFail();
        $this->assertSame(80.0, (float) $revision->old_score);
        $this->assertNull($revision->new_score);
        $this->assertSame('Siswa pindah kelas.', $revision->reason);

        // Nilai yang sama boleh dimasukkan lagi: barisnya dipulihkan, bukan
        // digandakan — indeks unik (assessment_id, student_id) menolak baris kedua.
        $this->postJson($this->scoreRoute($assessment), [
            'student_id' => $world['students'][0]->id,
            'score' => 84,
        ])->assertCreated();

        $this->assertSame(1, Score::withTrashed()->count());
        $this->assertFloat(84.0, Score::query()->sole()->score);
    }

    // =================================================================
    // Alur status, penguncian, dan hak akses
    // =================================================================

    public function test_submit_ditolak_bila_masih_ada_siswa_belum_dinilai(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);
        $this->actingAsRole('guru');

        $this->postJson($this->scoreRoute($assessment), [
            'student_id' => $world['students'][0]->id,
            'score' => 80,
        ])->assertCreated();

        $response = $this->postJson("/api/v1/assessments/{$assessment->id}/submit");

        $response->assertUnprocessable()->assertJsonValidationErrors('score');
        $this->assertErrorContains($response, 'score', 'Masih ada 1 siswa yang belum dinilai');
        $this->assertSame(Assessment::STATUS_DRAFT, $assessment->fresh()->status);
    }

    public function test_submit_paksa_hanya_diterima_bersama_alasan(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);
        $this->actingAsRole('guru');

        $this->postJson($this->scoreRoute($assessment), [
            'student_id' => $world['students'][0]->id,
            'score' => 80,
        ])->assertCreated();

        $this->postJson("/api/v1/assessments/{$assessment->id}/submit", ['force' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/assessments/{$assessment->id}/submit", [
            'force' => true,
            'reason' => 'Satu siswa ujian susulan, nilainya menyusul.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Assessment::STATUS_SUBMITTED);
    }

    public function test_alur_verifikasi_finalisasi_dan_penguncian_nilai(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);

        $this->actingAsRole('guru');
        $this->gradeAll($assessment, $world);

        // Guru mapel boleh menyerahkan, tetapi tidak boleh memverifikasi atau mengunci.
        $this->postJson("/api/v1/assessments/{$assessment->id}/verify")->assertForbidden();
        $this->postJson("/api/v1/assessments/{$assessment->id}/finalize")->assertForbidden();

        $this->actingAsRole('wali-kelas');

        // Belum diserahkan → belum bisa diverifikasi.
        $this->postJson("/api/v1/assessments/{$assessment->id}/verify")->assertUnprocessable();

        $this->actingAsRole('guru');
        $this->postJson("/api/v1/assessments/{$assessment->id}/submit")->assertOk();

        $this->actingAsRole('wali-kelas');
        $this->postJson("/api/v1/assessments/{$assessment->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', Assessment::STATUS_VERIFIED);

        $this->postJson("/api/v1/assessments/{$assessment->id}/finalize")
            ->assertOk()
            ->assertJsonPath('data.status', Assessment::STATUS_FINALIZED)
            ->assertJsonPath('data.is_locked', true);

        // Terkunci: skor, penilaian, dan remedial berhenti bisa diubah.
        $response = $this->postJson($this->scoreRoute($assessment), [
            'student_id' => $world['students'][0]->id,
            'score' => 100,
            'reason' => 'Mencoba mengubah nilai terkunci.',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('score');
        $this->assertErrorContains($response, 'score', 'sudah difinalisasi dan terkunci');

        $this->deleteJson("/api/v1/assessments/{$assessment->id}")->assertUnprocessable();

        $this->postJson("/api/v1/assessments/{$assessment->id}/remedials", [
            'student_id' => $world['students'][0]->id,
            'score_after' => 90,
        ])->assertUnprocessable();

        $this->assertSame(90.0, (float) Score::query()->firstOrFail()->score);
    }

    public function test_membuka_kembali_nilai_wajib_alasan_dan_dicatat_pada_metadata(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);

        $this->actingAsRole('guru');
        $this->gradeAll($assessment, $world);
        $this->postJson("/api/v1/assessments/{$assessment->id}/submit")->assertOk();

        $this->actingAsRole('admin-sekolah');
        $this->postJson("/api/v1/assessments/{$assessment->id}/finalize")->assertOk();

        $this->postJson("/api/v1/assessments/{$assessment->id}/reopen")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/assessments/{$assessment->id}/reopen", [
            'reason' => 'Ada nilai yang salah jumlah.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Assessment::STATUS_REVISION_REQUESTED)
            ->assertJsonPath('data.extra.reopened.reason', 'Ada nilai yang salah jumlah.');

        // Setelah dibuka, skor boleh diperbaiki — tetap wajib alasan.
        $this->postJson($this->scoreRoute($assessment), [
            'student_id' => $world['students'][0]->id,
            'score' => 95,
            'reason' => 'Koreksi rekapitulasi.',
        ])->assertOk();

        $this->assertSame(
            95.0,
            (float) Score::query()->where('student_id', $world['students'][0]->id)->value('score'),
        );
    }

    public function test_wali_kelas_tidak_bisa_menyetujui_penilaian_kelas_lain(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);
        $assessment->update(['status' => Assessment::STATUS_SUBMITTED]);

        // Wali kelas dari pegawai lain (bukan pengampu/wali kelas ini).
        $other = $this->makeTeacher('Guru Wali Lain');
        $wali = User::factory()->create(['is_active' => true]);
        $wali->forceFill([
            'userable_type' => $other->getMorphClass(),
            'userable_id' => $other->id,
        ])->save();
        $wali->syncRoles(['wali-kelas']);
        Sanctum::actingAs($wali, ['*']);

        $this->postJson("/api/v1/assessments/{$assessment->id}/verify")->assertForbidden();
        $this->postJson("/api/v1/assessments/{$assessment->id}/finalize")->assertForbidden();
    }

    public function test_pengampu_bisa_memverifikasi_penilaian_kelasnya(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);
        $assessment->update(['status' => Assessment::STATUS_SUBMITTED]);

        // Wali kelas yang sekaligus pengampu penilaian ini.
        $wali = User::factory()->create(['is_active' => true]);
        $wali->forceFill([
            'userable_type' => $world['teacher']->getMorphClass(),
            'userable_id' => $world['teacher']->id,
        ])->save();
        $wali->syncRoles(['wali-kelas']);
        Sanctum::actingAs($wali, ['*']);

        $this->postJson("/api/v1/assessments/{$assessment->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', Assessment::STATUS_VERIFIED);
    }

    public function test_guru_bukan_pengampu_tidak_bisa_membuat_penilaian_kelas_lain(): void
    {
        $world = $this->world();
        $type = $this->makeType($world);

        // Guru yang tertaut pegawai, tetapi bukan pengampu kelas+mapel ini.
        $other = $this->makeTeacher('Guru Mapel Lain');
        $guru = $this->actingAsRole('guru');
        $guru->forceFill([
            'userable_type' => $other->getMorphClass(),
            'userable_id' => $other->id,
        ])->save();

        $this->postJson('/api/v1/assessments', $this->assessmentPayload($world, $type))
            ->assertForbidden();
    }

    public function test_guru_bukan_pengampu_tidak_bisa_mengelola_remedial_kelas_lain(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);

        $other = $this->makeTeacher('Guru Mapel Lain');
        $guru = $this->actingAsRole('guru');
        $guru->forceFill([
            'userable_type' => $other->getMorphClass(),
            'userable_id' => $other->id,
        ])->save();

        $this->postJson("/api/v1/assessments/{$assessment->id}/remedials", [
            'student_id' => $world['students'][0]->id,
            'score_after' => 80,
        ])->assertForbidden();
    }

    public function test_penilaian_yang_dibuka_revisi_dapat_diajukan_ulang_dan_difinalisasi(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);

        $this->actingAsRole('guru');
        $this->gradeAll($assessment, $world);
        $this->postJson("/api/v1/assessments/{$assessment->id}/submit")->assertOk();

        $this->actingAsRole('admin-sekolah');
        $this->postJson("/api/v1/assessments/{$assessment->id}/finalize")->assertOk();
        $this->postJson("/api/v1/assessments/{$assessment->id}/reopen", ['reason' => 'Perbaikan nilai.'])
            ->assertOk()
            ->assertJsonPath('data.status', Assessment::STATUS_REVISION_REQUESTED);

        // Status revision_requested tidak boleh jadi jalan buntu.
        $this->actingAsRole('guru');
        $this->postJson("/api/v1/assessments/{$assessment->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', Assessment::STATUS_SUBMITTED);

        $this->actingAsRole('admin-sekolah');
        $this->postJson("/api/v1/assessments/{$assessment->id}/finalize")
            ->assertOk()
            ->assertJsonPath('data.status', Assessment::STATUS_FINALIZED);
    }

    public function test_memperbarui_catatan_saja_tidak_menghapus_nilai(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);
        $this->actingAsRole('guru');

        $created = $this->postJson($this->scoreRoute($assessment), [
            'student_id' => $world['students'][0]->id,
            'score' => 82,
        ])->assertCreated();

        $scoreId = $created->json('data.id');

        $this->patchJson("/api/v1/assessments/{$assessment->id}/scores/{$scoreId}", [
            'student_id' => $world['students'][0]->id,
            'notes' => 'Hanya mengubah catatan.',
        ])->assertOk();

        $this->assertSame(82.0, (float) Score::query()->whereKey($scoreId)->value('score'));
    }

    public function test_statistik_gradebook_menghitung_siswa_bernilai_nol(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);
        $this->actingAsRole('guru');

        $this->postJson($this->scoreRoute($assessment).'/bulk', ['entries' => [
            ['student_id' => $world['students'][0]->id, 'score' => 0],
            ['student_id' => $world['students'][1]->id, 'score' => 80],
        ]])->assertOk();

        $gradebook = $this->getJson($this->gradebookRoute($world))->assertOk()->json('data');

        $this->assertSame(2, $gradebook['summary']['scored']);
        $this->assertFloat(0.0, $gradebook['summary']['lowest']);
        $this->assertFloat(40.0, $gradebook['summary']['average']);
    }

    public function test_formula_penilaian_mengikuti_tahun_ajaran_penilaian(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);

        // Formula berlaku umum (tahun ajaran penilaian) dan formula versi baru
        // yang baru efektif tahun ajaran berikutnya.
        GradingFormula::create([
            'code' => 'OLD', 'name' => 'Formula Lama',
            'formula_type' => GradingFormula::TYPE_WEIGHTED_AVERAGE,
            'settings' => ['decimals' => 0], 'is_active' => true, 'version' => 1,
        ]);
        $nextYear = AcademicYear::create([
            'name' => '2027/2028', 'start_date' => '2027-07-01',
            'end_date' => '2028-06-30', 'is_active' => false,
        ]);
        GradingFormula::create([
            'code' => 'NEW', 'name' => 'Formula Baru',
            'formula_type' => GradingFormula::TYPE_WEIGHTED_AVERAGE,
            'settings' => ['decimals' => 4], 'is_active' => true, 'version' => 99,
            'effective_academic_year_id' => $nextYear->id,
        ]);

        $this->actingAsRole('guru');
        $this->gradeAll($assessment, $world, 88);

        $gradebook = $this->getJson($this->gradebookRoute($world))->assertOk()->json('data');

        // Harus memakai formula tahun penilaian (desimal 0), bukan versi 99
        // milik tahun ajaran berikutnya.
        $this->assertSame('OLD', $gradebook['formula']['code']);
        $this->assertSame(0, $gradebook['formula']['decimals']);
    }

    public function test_nilai_draft_tidak_bisa_dibuka_kembali(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);
        $this->actingAsRole('wali-kelas');

        $response = $this->postJson("/api/v1/assessments/{$assessment->id}/reopen", [
            'reason' => 'Mencoba membuka nilai draft.',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertErrorContains($response, 'status', 'diserahkan, diverifikasi, atau difinalisasi');
    }

    public function test_nilai_yang_baru_diserahkan_bisa_langsung_dikembalikan_ke_revisi(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);
        $assessment->update(['status' => Assessment::STATUS_SUBMITTED]);

        // Verifikator menemukan masalah sebelum memverifikasi; ia tidak perlu
        // memverifikasi dulu (yang justru mengesahkan nilai bermasalah).
        $this->actingAsRole('admin-sekolah');

        $this->postJson("/api/v1/assessments/{$assessment->id}/reopen", [
            'reason' => 'Ada nilai yang belum lengkap.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', Assessment::STATUS_REVISION_REQUESTED)
            ->assertJsonPath('data.extra.reopened.from_status', Assessment::STATUS_SUBMITTED);
    }

    public function test_riwayat_reopen_ditambahkan_bukan_ditimpa(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);

        $this->actingAsRole('admin-sekolah');

        $assessment->update(['status' => Assessment::STATUS_FINALIZED]);
        $this->postJson("/api/v1/assessments/{$assessment->id}/reopen", ['reason' => 'Revisi pertama.'])
            ->assertOk();

        // Kembalikan ke status terkunci untuk membuka lagi, lalu pastikan
        // riwayat lama tetap tersimpan.
        $assessment->refresh()->update(['status' => Assessment::STATUS_FINALIZED]);

        $this->postJson("/api/v1/assessments/{$assessment->id}/reopen", ['reason' => 'Revisi kedua.'])
            ->assertOk()
            ->assertJsonPath('data.extra.reopened.reason', 'Revisi kedua.')
            ->assertJsonCount(2, 'data.extra.reopenings');
    }

    public function test_siswa_boleh_membaca_nilainya_tetapi_tidak_menulis(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);

        $this->actingAsRole('guru');
        $this->postJson($this->scoreRoute($assessment), [
            'student_id' => $world['students'][0]->id,
            'score' => 86,
        ])->assertCreated();

        $siswa = $this->actingAsRole('siswa');
        $siswa->update([
            'userable_type' => $world['students'][0]->getMorphClass(),
            'userable_id' => $world['students'][0]->id,
        ]);

        $this->postJson($this->scoreRoute($assessment), [
            'student_id' => $world['students'][0]->id,
            'score' => 100,
        ])->assertForbidden();

        $rows = $this->getJson('/api/v1/students/'.$world['students'][0]->id.'/scores?semester_id='.$world['semester']->id)
            ->assertOk()
            ->json('data');

        $this->assertFloat(86.0, $rows[0]['score']);
        $this->assertSame('B', $rows[0]['grade']);
        $this->assertFloat(86.0, $rows[0]['percentage']);
    }

    // =================================================================
    // Perhitungan nilai akhir
    // =================================================================

    public function test_nilai_akhir_dihitung_dengan_bobot_dan_normalisasi_persen(): void
    {
        $world = $this->world();
        $this->actingAsRole('guru');

        $tugas = $this->makeType($world, 'Tugas Harian');
        $kuis = $this->makeType($world, 'Kuis', 'kuis');
        $sas = $this->makeType($world, 'Sumatif Akhir Semester', 'sas');

        $this->makeWeight($world, $tugas, 20);
        $this->makeWeight($world, $kuis, 10);
        $this->makeWeight($world, $sas, 15);

        $aTugas = $this->makeAssessment($world, ['assessment_type_id' => $tugas->id, 'max_score' => 100]);
        $aKuis = $this->makeAssessment($world, [
            'assessment_type_id' => $kuis->id,
            'max_score' => 40,
            'name' => 'Kuis Bab 2',
        ]);
        $this->makeAssessment($world, ['assessment_type_id' => $sas->id, 'name' => 'Sumatif Akhir']);

        $student = $world['students'][0];

        // 90/100 = 90% dan 20/40 = 50% — skala berbeda harus dinormalisasi dulu.
        $this->postJson($this->scoreRoute($aTugas), ['student_id' => $student->id, 'score' => 90])->assertCreated();
        $this->postJson($this->scoreRoute($aKuis), ['student_id' => $student->id, 'score' => 20])->assertCreated();

        $gradebook = $this->getJson($this->gradebookRoute($world))->assertOk()->json('data');

        $this->assertFloat(45.0, $gradebook['weight_total']);
        $this->assertFalse($gradebook['weights_complete']);
        $this->assertFloat(75.0, $gradebook['passing_grade']);
        $this->assertSame(3, $gradebook['assessment_count']);

        $row = collect($gradebook['students'])->firstWhere('student_id', $student->id);

        // (90×20 + 50×10) ÷ 30 — SAS yang belum dinilai tidak memperbesar pembagi.
        $this->assertFloat(76.67, $row['final_score']);
        $this->assertSame('C', $row['grade']);
        $this->assertSame('Cukup', $row['predicate']);
        $this->assertTrue($row['tuntas']);
        $this->assertCount(2, $row['items']);
        $this->assertFloat(20.0, $row['items'][0]['weight']);

        $empty = collect($gradebook['students'])->firstWhere('student_id', $world['students'][1]->id);
        $this->assertNull($empty['final_score']);
        $this->assertNull($empty['tuntas']);

        $this->assertSame(1, $gradebook['summary']['scored']);
        $this->assertSame(1, $gradebook['summary']['unscored']);
        $this->assertFloat(76.67, $gradebook['summary']['average']);
    }

    public function test_bobot_spesifik_penilaian_menimpa_bobot_jenis(): void
    {
        $world = $this->world();
        $this->actingAsRole('guru');

        $tugas = $this->makeType($world, 'Tugas Harian');
        $kuis = $this->makeType($world, 'Kuis', 'kuis');
        $this->makeWeight($world, $tugas, 20);
        $this->makeWeight($world, $kuis, 10); // ditimpa bobot assessment

        $aTugas = $this->makeAssessment($world, ['assessment_type_id' => $tugas->id]);
        $aKuis = $this->makeAssessment($world, [
            'assessment_type_id' => $kuis->id,
            'max_score' => 40,
            'weight' => 5,
            'name' => 'Kuis Bab 2',
        ]);

        $student = $world['students'][0];
        $this->postJson($this->scoreRoute($aTugas), ['student_id' => $student->id, 'score' => 90])->assertCreated();
        $this->postJson($this->scoreRoute($aKuis), ['student_id' => $student->id, 'score' => 20])->assertCreated();

        $gradebook = $this->getJson($this->gradebookRoute($world))->assertOk()->json('data');

        $kuisWeight = collect($gradebook['weights'])->firstWhere('assessment_id', $aKuis->id);
        $this->assertFloat(5.0, $kuisWeight['weight']);
        $this->assertSame('assessment', $kuisWeight['weight_source']);

        // (90×20 + 50×5) ÷ 25 = 82
        $row = collect($gradebook['students'])->firstWhere('student_id', $student->id);
        $this->assertFloat(82.0, $row['final_score']);
        $this->assertSame('B', $row['grade']);
    }

    public function test_penilaian_tanpa_bobot_tidak_menghasilkan_nilai_akhir_palsu(): void
    {
        $world = $this->world();
        $type = $this->makeType($world, 'Tugas Harian'); // tanpa baris assessment_weights
        $assessment = $this->makeAssessment($world, ['assessment_type_id' => $type->id]);
        $this->actingAsRole('guru');

        $this->postJson($this->scoreRoute($assessment), [
            'student_id' => $world['students'][0]->id,
            'score' => 90,
        ])->assertCreated();

        $gradebook = $this->getJson($this->gradebookRoute($world))->assertOk()->json('data');

        $this->assertFalse($gradebook['weights_complete']);
        $this->assertFloat(0.0, $gradebook['weights'][0]['weight']);
        $this->assertSame('none', $gradebook['weights'][0]['weight_source']);
        // Pembagi bobot nol → tidak ada nilai akhir, bukan error atau nilai 0.
        $this->assertNull(collect($gradebook['students'])->firstWhere('student_id', $world['students'][0]->id)['final_score']);
    }

    public function test_nilai_akhir_ikut_berubah_saat_bobot_diubah(): void
    {
        $world = $this->world();
        $this->actingAsRole('guru');

        $tugas = $this->makeType($world, 'Tugas Harian');
        $kuis = $this->makeType($world, 'Kuis', 'kuis');
        $wTugas = $this->makeWeight($world, $tugas, 50);
        $wKuis = $this->makeWeight($world, $kuis, 50);

        $aTugas = $this->makeAssessment($world, ['assessment_type_id' => $tugas->id]);
        $aKuis = $this->makeAssessment($world, ['assessment_type_id' => $kuis->id, 'name' => 'Kuis Bab 2']);

        $student = $world['students'][0];
        $this->postJson($this->scoreRoute($aTugas), ['student_id' => $student->id, 'score' => 100])->assertCreated();
        $this->postJson($this->scoreRoute($aKuis), ['student_id' => $student->id, 'score' => 40])->assertCreated();

        $finalOf = fn () => (float) collect($this->getJson($this->gradebookRoute($world))->assertOk()->json('data')['students'])
            ->firstWhere('student_id', $student->id)['final_score'];

        // Nilai akhir tidak disimpan di tabel mana pun: selalu dihitung ulang.
        $this->assertSame(70.0, $finalOf());

        $wTugas->update(['weight' => 90]);
        $wKuis->update(['weight' => 10]);

        $this->assertSame(94.0, $finalOf());
    }

    public function test_desimal_nilai_akhir_mengikuti_setting_formula(): void
    {
        $world = $this->world();
        $this->actingAsRole('guru');

        $formula = GradingFormula::create([
            'code' => 'BULAT-0',
            'name' => 'Tanpa desimal',
            'formula_type' => GradingFormula::TYPE_WEIGHTED_AVERAGE,
            'settings' => ['decimals' => 0],
            'is_active' => false,
        ]);

        $assessment = $this->makeAssessment($world, [
            'grading_formula_id' => $formula->id,
            'max_score' => 40,
        ]);

        $student = $world['students'][0];
        $this->postJson($this->scoreRoute($assessment), ['student_id' => $student->id, 'score' => 23])->assertCreated();

        // 23/40 = 57,5% → dibulatkan tanpa desimal menjadi 58.
        $row = collect($this->getJson($this->gradebookRoute($world))->assertOk()->json('data')['students'])
            ->firstWhere('student_id', $student->id);

        $this->assertFloat(58.0, $row['final_score']);
        $this->assertSame('E', $row['grade']);
    }

    public function test_kelas_tanpa_skor_tidak_memicu_pembagian_nol(): void
    {
        $world = $this->world();
        $this->makeAssessment($world);
        $this->actingAsRole('guru');

        $gradebook = $this->getJson($this->gradebookRoute($world))->assertOk()->json('data');

        $this->assertSame(0, $gradebook['summary']['scored']);
        $this->assertSame(2, $gradebook['summary']['unscored']);
        $this->assertNull($gradebook['summary']['average']);
        $this->assertNull($gradebook['summary']['pass_rate']);
        $this->assertNull($gradebook['students'][0]['final_score']);
    }

    public function test_statistik_penilaian_menyajikan_sebaran_dan_siswa_di_bawah_kktp(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);
        $this->actingAsRole('guru');

        $this->postJson($this->scoreRoute($assessment), ['student_id' => $world['students'][0]->id, 'score' => 90])->assertCreated();
        $this->postJson($this->scoreRoute($assessment), ['student_id' => $world['students'][1]->id, 'score' => 70])->assertCreated();

        $stats = $this->getJson("/api/v1/assessments/{$assessment->id}/stats")->assertOk()->json('data');

        $this->assertSame(2, $stats['graded']);
        $this->assertSame(0, $stats['pending']);
        $this->assertFloat(80.0, $stats['average']);
        $this->assertFloat(75.0, $stats['passing_grade']);
        $this->assertFloat(50.0, $stats['pass_rate']);
        $this->assertSame(1, collect($stats['distribution'])->firstWhere('label', '70-79')['count']);
        $this->assertSame($world['students'][1]->id, $stats['below_passing'][0]['student_id']);
    }

    public function test_lembar_nilai_mencantumkan_siswa_yang_belum_dinilai(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);
        $this->actingAsRole('guru');

        $this->postJson($this->scoreRoute($assessment), [
            'student_id' => $world['students'][0]->id,
            'score' => 78,
        ])->assertCreated();

        $sheet = $this->getJson("/api/v1/assessments/{$assessment->id}/sheet")->assertOk()->json('data');

        $this->assertSame(2, $sheet['total']);
        $this->assertSame(1, $sheet['graded']);
        $this->assertSame(1, $sheet['pending']);
        $this->assertFloat(100.0, $sheet['max_score']);
        $this->assertFalse($sheet['is_locked']);

        $graded = collect($sheet['items'])->firstWhere('graded', true);
        $this->assertFloat(78.0, $graded['score']);
        $this->assertSame('C', $graded['grade']);

        $pending = collect($sheet['items'])->firstWhere('graded', false);
        $this->assertNull($pending['score']);
    }

    // =================================================================
    // Remedial
    // =================================================================

    public function test_remedial_max_tidak_menurunkan_nilai_siswa(): void
    {
        // Aturan default `max`: 85 menggantikan 50 karena lebih tinggi.
        $this->assertSame(85.0, $this->remedialFinalScore($this->world(), ['score_after' => 85]));
    }

    public function test_remedial_max_tidak_menaikkan_nilai_yang_sudah_lebih_tinggi(): void
    {
        $this->assertSame(90.0, $this->remedialFinalScore($this->world(), [
            'score' => 90,
            'score_after' => 60,
        ]));
    }

    public function test_remedial_average_menggabungkan_nilai_awal_dan_nilai_perbaikan(): void
    {
        // (50 + 85) ÷ 2
        $this->assertSame(67.5, $this->remedialFinalScore(
            $this->world(),
            ['score_after' => 85],
            $this->makeFormula('AVG', ['remedial_rule' => GradingFormula::REMEDIAL_AVERAGE]),
        ));
    }

    public function test_remedial_cap_membatasi_hasil_nilai_perbaikan(): void
    {
        $formula = $this->makeFormula('CAP', [
            'remedial_rule' => GradingFormula::REMEDIAL_CAP,
            'remedial_cap' => 75,
        ]);

        $this->assertSame(75.0, $this->remedialFinalScore($this->world(), ['score_after' => 85], $formula));
    }

    public function test_remedial_yang_belum_selesai_belum_mempengaruhi_nilai_akhir(): void
    {
        $this->assertSame(50.0, $this->remedialFinalScore($this->world(), ['score_after' => null]));
    }

    public function test_catatan_remedial_unik_per_penilaian_dan_siswa(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world, ['score' => 50]);
        $this->actingAsRole('guru');

        $payload = [
            'student_id' => $world['students'][0]->id,
            'score_after' => 80,
            'date' => '2026-09-20',
            'notes' => 'Remedial tulis ulang soal 3.',
        ];

        // Nilai awal diambil otomatis dari skor yang sudah tercatat.
        $created = $this->postJson("/api/v1/assessments/{$assessment->id}/remedials", $payload);

        $created->assertCreated()->assertJsonPath('data.status', 'done');
        $this->assertFloat(50.0, $created->json('data.score_before'));

        $payload['score_after'] = 85;

        $updated = $this->postJson("/api/v1/assessments/{$assessment->id}/remedials", $payload);

        $updated->assertOk();
        $this->assertFloat(85.0, $updated->json('data.score_after'));
        $this->assertFloat(35.0, $updated->json('data.improvement'));

        $this->assertSame(1, Remedial::query()->count());
    }

    public function test_remedial_ditolak_bila_melebihi_skor_maksimum_penilaian(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world, ['score' => 50]);
        $this->actingAsRole('guru');

        $response = $this->postJson("/api/v1/assessments/{$assessment->id}/remedials", [
            'student_id' => $world['students'][0]->id,
            'score_after' => 130,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('score_after');
        $this->assertErrorContains($response, 'score_after', 'tidak boleh melebihi skor maksimum');
    }

    public function test_kandidat_remedial_diambil_dari_siswa_di_bawah_kktp(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);
        $this->actingAsRole('guru');

        $this->postJson($this->scoreRoute($assessment), ['student_id' => $world['students'][0]->id, 'score' => 90])->assertCreated();
        $this->postJson($this->scoreRoute($assessment), ['student_id' => $world['students'][1]->id, 'score' => 62])->assertCreated();

        $candidates = $this->getJson("/api/v1/assessments/{$assessment->id}/remedials/candidates")
            ->assertOk()
            ->json('data');

        $this->assertFloat(75.0, $candidates['passing_grade']);
        $this->assertSame(1, $candidates['total']);
        $this->assertSame($world['students'][1]->id, $candidates['items'][0]['student_id']);
    }

    public function test_naik_kktp_membuat_siswa_berubah_menjadi_belum_tuntas(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);

        $this->actingAsRole('guru');
        $this->gradeAll($assessment, $world, 78);

        $before = $this->getJson($this->gradebookRoute($world))->assertOk()->json('data');
        $this->assertTrue($before['students'][0]['tuntas']);
        $this->assertSame(2, $before['summary']['tuntas']);

        PassingGradeRule::query()->update(['min_score' => 80]);

        $after = $this->getJson($this->gradebookRoute($world))->assertOk()->json('data');
        $this->assertFloat(78.0, $after['students'][0]['final_score']);
        $this->assertFalse($after['students'][0]['tuntas']);
        $this->assertSame(0, $after['summary']['tuntas']);
        $this->assertSame(2, $after['summary']['belum_tuntas']);
        $this->assertCount(2, $after['needs_attention']);
    }

    // =================================================================
    // Konfigurasi penilaian
    // =================================================================

    public function test_jenis_penilaian_yang_masih_dipakai_tidak_bisa_dihapus(): void
    {
        $world = $this->world();
        $assessment = $this->makeAssessment($world);
        $this->actingAsRole('admin-sekolah');

        $response = $this->deleteJson("/api/v1/assessment-types/{$assessment->assessment_type_id}");

        $response->assertUnprocessable()->assertJsonValidationErrors('id');
        $this->assertErrorContains($response, 'id', 'Nonaktifkan saja.');

        $this->assertDatabaseHas('assessment_types', ['id' => $assessment->assessment_type_id]);
    }

    public function test_skala_nilai_menolak_rentang_yang_tumpang_tindih_terbalik_ataupun_duplikat(): void
    {
        $this->actingAsRole('admin-sekolah');
        $this->makeScale();

        $overlapping = $this->postJson('/api/v1/grade-scales', [
            'code' => 'CAMP',
            'name' => 'Skala percobaan',
            'items' => [
                ['grade' => 'A', 'min_score' => 80, 'max_score' => 100, 'predicate' => 'Sangat Baik'],
                ['grade' => 'B', 'min_score' => 85, 'max_score' => 95, 'predicate' => 'Baik'],
            ],
        ])->assertUnprocessable();
        $this->assertErrorContains($overlapping, 'items', 'tumpang tindih');

        $reversed = $this->postJson('/api/v1/grade-scales', [
            'code' => 'CAMREV',
            'name' => 'Skala terbalik',
            'items' => [
                ['grade' => 'A', 'min_score' => 100, 'max_score' => 80],
            ],
        ])->assertUnprocessable();
        $this->assertErrorContains($reversed, 'items', 'tidak boleh lebih besar');

        $duplicated = $this->postJson('/api/v1/grade-scales', [
            'code' => 'CAMDUP',
            'name' => 'Huruf kembar',
            'items' => [
                ['grade' => 'A', 'min_score' => 90, 'max_score' => 100],
                ['grade' => 'A', 'min_score' => 0, 'max_score' => 10],
            ],
        ])->assertUnprocessable();
        $this->assertErrorContains($duplicated, 'items', 'lebih dari satu rentang');

        $this->postJson('/api/v1/grade-scales', [
            'code' => 'A-E',
            'name' => 'Kode duplikat',
            'items' => [['grade' => 'A', 'min_score' => 90, 'max_score' => 100]],
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $valid = $this->postJson('/api/v1/grade-scales', [
            'code' => 'OK',
            'name' => 'Skala sah',
            'items' => [
                ['grade' => 'A', 'min_score' => 90, 'max_score' => 100],
                ['grade' => 'B', 'min_score' => 80, 'max_score' => 89.99],
            ],
        ])->assertCreated();

        $this->assertCount(2, $valid->json('data.items'));
    }

    public function test_skala_nilai_khusus_jenjang_dipilih_lebih_dahulu_dari_skala_umum(): void
    {
        $general = $this->makeScale();
        $level = \App\Models\EducationLevel::create([
            'name' => 'Kelas 1',
            'jenjang' => 'SMA',
            'level_number' => 1,
            'is_active' => true,
        ]);

        $this->assertSame($general->id, GradeScale::resolveFor($level->id)?->id);

        $specific = GradeScale::create([
            'code' => 'SMA-A-E',
            'name' => 'Skala jenjang SMA',
            'education_level_id' => $level->id,
            'is_active' => true,
        ]);

        GradeScaleItem::create([
            'grade_scale_id' => $specific->id,
            'min_score' => 0,
            'max_score' => 100,
            'grade' => 'T',
            'predicate' => 'Terstandar',
        ]);

        $this->assertSame($specific->id, GradeScale::resolveFor($level->id)?->id);
        $this->assertSame($general->id, GradeScale::resolveFor(null)?->id);
        $this->assertSame('T', $specific->convert(70)['grade']);
    }

    public function test_kktp_dipilih_dari_aturan_paling_spesifik(): void
    {
        $world = $this->world();
        $this->actingAsRole('guru');

        $resolve = fn () => $this->getJson('/api/v1/passing-grades/resolve?class_id='.$world['class']->id.'&subject_id='.$world['subject']->id)
            ->assertOk()
            ->json('data');

        $general = $resolve();

        $this->assertFloat(75.0, $general['min_score']);
        $this->assertSame('umum_sekolah', $general['source']['scope']);

        $this->postJson('/api/v1/passing-grades', [
            'subject_id' => $world['subject']->id,
            'min_score' => 88,
        ])->assertCreated();

        $specific = $resolve();

        $this->assertFloat(88.0, $specific['min_score']);
        $this->assertSame('mata_pelajaran', $specific['source']['scope']);

        // Dua aturan dengan konteks sama akan membuat hasilnya tergantung urutan baris.
        $response = $this->postJson('/api/v1/passing-grades', [
            'subject_id' => $world['subject']->id,
            'min_score' => 90,
        ])->assertUnprocessable();
        $this->assertErrorContains($response, 'subject_id', 'sudah ada');
    }

    public function test_bobot_penilaian_per_kelas_ditolak_bila_melebihi_seratus_persen(): void
    {
        $world = $this->world();
        $tugas = $this->makeType($world, 'Tugas Harian');
        $kuis = $this->makeType($world, 'Kuis', 'kuis');
        $this->actingAsRole('admin-sekolah');

        $overTotal = $this->postJson('/api/v1/assessment-weights', [
            'class_id' => $world['class']->id,
            'entries' => [
                ['assessment_type_id' => $tugas->id, 'weight' => 60],
                ['assessment_type_id' => $kuis->id, 'weight' => 60],
            ],
        ])->assertUnprocessable();
        $this->assertErrorContains($overTotal, 'entries', 'melebihi 100%');

        $duplicated = $this->postJson('/api/v1/assessment-weights', [
            'class_id' => $world['class']->id,
            'entries' => [
                ['assessment_type_id' => $tugas->id, 'weight' => 20],
                ['assessment_type_id' => $tugas->id, 'weight' => 30],
            ],
        ])->assertUnprocessable();
        $this->assertErrorContains($duplicated, 'entries', 'tidak boleh diberi bobot lebih dari sekali');

        $saved = $this->postJson('/api/v1/assessment-weights', [
            'class_id' => $world['class']->id,
            'entries' => [
                ['assessment_type_id' => $tugas->id, 'weight' => 60],
                ['assessment_type_id' => $kuis->id, 'weight' => 40],
            ],
        ]);

        $saved->assertOk();
        $this->assertFloat(100.0, $saved->json('data.weight_total'));

        $listed = $this->getJson('/api/v1/assessment-weights?class_id='.$world['class']->id)
            ->assertOk()
            ->json('data');

        $this->assertFloat(100.0, $listed['weight_total']);
        $this->assertTrue($listed['weights_complete']);

        // Gradebook memakai bobot jenis yang sama → totalnya 100% bila semua jenis diuji.
        $this->makeAssessment($world, ['assessment_type_id' => $tugas->id]);
        $this->makeAssessment($world, ['assessment_type_id' => $kuis->id, 'name' => 'Kuis Bab 2']);

        $gradebook = $this->getJson($this->gradebookRoute($world))
            ->assertOk()
            ->json('data');

        $this->assertFloat(100.0, $gradebook['weight_total']);
        $this->assertTrue($gradebook['weights_complete']);
    }

    public function test_formula_yang_masih_dipakai_penilaian_tidak_bisa_dihapus(): void
    {
        $world = $this->world();
        $formula = GradingFormula::create([
            'code' => 'PAKAI',
            'name' => 'Formula terpakai',
            'formula_type' => GradingFormula::TYPE_WEIGHTED_AVERAGE,
            'is_active' => true,
        ]);
        $this->makeAssessment($world, ['grading_formula_id' => $formula->id]);
        $this->actingAsRole('admin-sekolah');

        $response = $this->deleteJson("/api/v1/grading-formulas/{$formula->id}")->assertUnprocessable();
        $this->assertErrorContains($response, 'id', 'masih dipakai');

        $this->assertDatabaseHas('grading_formulas', ['id' => $formula->id]);
    }

    public function test_setting_formula_remedial_divalidasi_saat_disimpan(): void
    {
        $this->actingAsRole('admin-sekolah');

        $response = $this->postJson('/api/v1/grading-formulas', [
            'code' => 'BATAS',
            'name' => 'Remedial dibatasi tanpa batas',
            'formula_type' => GradingFormula::TYPE_WEIGHTED_AVERAGE,
            'settings' => ['remedial_rule' => GradingFormula::REMEDIAL_CAP],
        ])->assertUnprocessable();

        $response->assertJsonValidationErrors('settings.remedial_cap');
    }

    // =================================================================
    // Helper
    // =================================================================

    /**
     * Dunia minimal: satu kelas + mapel yang diampu satu guru, dua siswa aktif,
     * skala nilai A–E, dan KKTP umum 75. `key` membuat semua kode unik sehingga
     * satu test boleh memakai beberapa dunia sekaligus.
     *
     * @return array{semester: Semester, class: SchoolClass, subject: Subject, teacher: Employee, students: list<Student>}
     */
    private function world(string $key = 'a'): array
    {
        $semester = $this->makeSemester();

        $class = SchoolClass::create([
            'academic_year_id' => $semester->academic_year_id,
            'name' => "Kelas {$key}",
            'code' => "KEL-{$key}",
            'capacity' => 32,
            'is_active' => true,
        ]);

        $subject = $this->makeSubject("MPL-{$key}");
        $teacher = $this->makeTeacher("Guru {$key}");

        ClassSubject::create([
            'school_class_id' => $class->id,
            'subject_id' => $subject->id,
            'employee_id' => $teacher->id,
            'academic_year_id' => $semester->academic_year_id,
        ]);

        $students = [
            $this->makeStudent("NIS-{$key}01", "Andi Pratama {$key}"),
            $this->makeStudent("NIS-{$key}02", "Bunga Lestari {$key}"),
        ];

        foreach ($students as $student) {
            ClassStudent::create([
                'school_class_id' => $class->id,
                'student_id' => $student->id,
                'academic_year_id' => $semester->academic_year_id,
            ]);
        }

        $this->makeScale();
        $this->makeGeneralPassingGrade();

        return compact('semester', 'class', 'subject', 'teacher', 'students');
    }

    private function makeSemester(): Semester
    {
        $year = AcademicYear::firstOrCreate(
            ['name' => '2025/2026'],
            ['start_date' => '2025-07-01', 'end_date' => '2026-06-30', 'is_active' => true],
        );

        return Semester::firstOrCreate(
            ['academic_year_id' => $year->id, 'name' => 'Ganjil'],
            ['start_date' => '2026-07-01', 'end_date' => '2026-12-31', 'is_active' => true],
        );
    }

    private function makeSubject(string $code, string $name = 'Matematika'): Subject
    {
        return Subject::create([
            'code' => $code,
            'name' => $name,
            'category' => 'Wajib',
            'credit' => 4,
            'is_active' => true,
        ]);
    }

    private function makeTeacher(string $name): Employee
    {
        return Employee::create([
            'full_name' => $name,
            'employee_type' => 'guru',
            'employment_status' => 'PNS',
            'is_teacher' => true,
            'is_active' => true,
        ]);
    }

    private function makeStudent(string $nis, string $name): Student
    {
        return Student::create([
            'nis' => $nis,
            'full_name' => $name,
            'is_active' => true,
        ]);
    }

    private function makeType(array $world, string $name = 'Tugas Harian', string $code = 'tugas'): AssessmentType
    {
        return AssessmentType::firstOrCreate(
            ['code' => $code.'-'.$world['class']->code],
            [
                'name' => $name,
                'category' => 'pengetahuan',
                'is_required' => false,
                'sort_order' => 10,
                'is_active' => true,
            ],
        );
    }

    private function makeWeight(array $world, AssessmentType $type, float $weight): AssessmentWeight
    {
        return AssessmentWeight::updateOrCreate(
            [
                'school_class_id' => $world['class']->id,
                'academic_year_id' => $world['semester']->academic_year_id,
                'assessment_type_id' => $type->id,
            ],
            ['weight' => $weight],
        );
    }

    private function makeScale(): GradeScale
    {
        $scale = GradeScale::firstOrCreate(
            ['code' => 'A-E'],
            ['name' => 'Skala Huruf A-E', 'is_active' => true],
        );

        foreach ([
            ['A', 90, 100, 'Sangat Baik'],
            ['B', 80, 89.99, 'Baik'],
            ['C', 70, 79.99, 'Cukup'],
            ['D', 60, 69.99, 'Kurang'],
            ['E', 0, 59.99, 'Sangat Kurang'],
        ] as [$grade, $min, $max, $predicate]) {
            GradeScaleItem::firstOrCreate(
                ['grade_scale_id' => $scale->id, 'grade' => $grade],
                ['min_score' => $min, 'max_score' => $max, 'predicate' => $predicate],
            );
        }

        return $scale->load('items');
    }

    private function makeGeneralPassingGrade(): PassingGradeRule
    {
        return PassingGradeRule::firstOrCreate(
            ['subject_id' => null, 'education_level_id' => null, 'academic_year_id' => null],
            ['min_score' => 75],
        );
    }

    private function makeFormula(string $code, array $settings): GradingFormula
    {
        return GradingFormula::create([
            'code' => $code,
            'name' => "Formula {$code}",
            'formula_type' => GradingFormula::TYPE_WEIGHTED_AVERAGE,
            'settings' => $settings,
            'is_active' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $world
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function assessmentPayload(array $world, AssessmentType $type, array $overrides = []): array
    {
        return array_merge([
            'class_id' => $world['class']->id,
            'subject_id' => $world['subject']->id,
            'semester_id' => $world['semester']->id,
            'assessment_type_id' => $type->id,
            'name' => 'Tugas Bab 1',
            'max_score' => 100,
            'date' => '2026-09-10',
        ], $overrides);
    }

    /**
     * Buat assessment lewat model (cepat, tanpa lewat API). `score` menulis satu
     * skor untuk siswa pertama lewat API supaya jalur penulisan aslinya terpakai.
     *
     * @param  array<string, mixed>  $world
     * @param  array<string, mixed>  $overrides
     */
    private function makeAssessment(array $world, array $overrides = []): Assessment
    {
        $score = $overrides['score'] ?? null;
        unset($overrides['score']);

        if (! isset($overrides['assessment_type_id'])) {
            $type = $this->makeType($world, 'Tugas Harian');
            $this->makeWeight($world, $type, 20);
            $overrides['assessment_type_id'] = $type->id;
        }

        $assessment = Assessment::create(array_merge([
            'school_class_id' => $world['class']->id,
            'subject_id' => $world['subject']->id,
            'semester_id' => $world['semester']->id,
            'employee_id' => $world['teacher']->id,
            'name' => 'Tugas Bab 1',
            'max_score' => 100,
            'date' => '2026-09-10',
            'status' => Assessment::STATUS_DRAFT,
        ], $overrides));

        if ($score !== null) {
            $this->actingAsRole('guru');

            $this->postJson($this->scoreRoute($assessment), [
                'student_id' => $world['students'][0]->id,
                'score' => $score,
            ])->assertCreated();
        }

        return $assessment;
    }

    /**
     * Nilai semua siswa pada satu angka — transisi status mensyaratkan penilaian lengkap.
     *
     * @param  array<string, mixed>  $world
     */
    private function gradeAll(Assessment $assessment, array $world, float $score = 90): void
    {
        $entries = [];

        foreach ($world['students'] as $student) {
            $entries[] = ['student_id' => $student->id, 'score' => $score];
        }

        $this->postJson($this->scoreRoute($assessment).'/bulk', ['entries' => $entries])
            ->assertOk();
    }

    /**
     * Satu skenario remedial: nilai awal, nilai perbaikan, lalu lihat nilai
     * akhirnya lewat gradebook — cara remedial dihitung adalah aturan formula.
     *
     * @param  array<string, mixed>  $world
     * @param  array<string, mixed>  $remedial
     */
    private function remedialFinalScore(array $world, array $remedial, ?GradingFormula $formula = null): float
    {
        $assessment = $this->makeAssessment($world, [
            'score' => $remedial['score'] ?? 50,
            'grading_formula_id' => $formula?->id,
        ]);

        $this->actingAsRole('guru');

        $this->postJson("/api/v1/assessments/{$assessment->id}/remedials", [
            'student_id' => $world['students'][0]->id,
            'score_after' => $remedial['score_after'] ?? null,
            'notes' => 'Remedial.',
        ])->assertCreated();

        $gradebook = $this->getJson($this->gradebookRoute($world))->assertOk()->json('data');

        $row = collect($gradebook['students'])->firstWhere('student_id', $world['students'][0]->id);

        return (float) $row['final_score'];
    }

    private function scoreRoute(Assessment $assessment): string
    {
        return "/api/v1/assessments/{$assessment->id}/scores";
    }

    /**
     * @param  array<string, mixed>  $world
     */
    private function gradebookRoute(array $world): string
    {
        return '/api/v1/gradebook?class_id='.$world['class']->id
            .'&subject_id='.$world['subject']->id
            .'&semester_id='.$world['semester']->id;
    }

    /**
     * Pesan validasi per field, agar ujiannya ikut membuktikan pesan yang dibaca guru.
     */
    private function assertErrorContains(TestResponse $response, string $field, string $fragment): void
    {
        $messages = (array) ($response->json("errors.{$field}") ?? []);

        $this->assertTrue(
            collect($messages)->contains(fn (string $message) => str_contains($message, $fragment)),
            "Pesan untuk '{$field}' tidak memuat '{$fragment}'. Nyata: ".json_encode($messages),
        );
    }

    /**
     * Bandingkan nilai desimal dari respons JSON.
     *
     * JSON tidak membedakan 45 dan 45.0 — angka desimal yang kebetulan bulat
     * dikodekan tanpa titik desimal, sehingga `assertSame(45.0, …)` akan gagal
     * membandingkannya dengan int hasil dekode.
     */
    private function assertFloat(float $expected, mixed $actual): void
    {
        // Tanpa pemeriksaan ini, null akan luruh menjadi 0.0 dan ujiannya lolos palsu.
        $this->assertNotNull($actual, "Nilai yang seharusnya {$expected} kosong di respons.");
        $this->assertSame($expected, (float) $actual);
    }

    private function actingAsRole(string $role): User
    {
        $email = str_replace('-', '.', $role).'@schoolerp.test';

        // Satu pengguna per role dipakai ulang: sebagian test butuh berganti role
        // tengah test, dan membuat dua akun beralamat sama akan menabrak unik email.
        $user = User::query()->firstWhere('email', $email)
            ?? User::factory()->create(['email' => $email, 'is_active' => true]);

        $user->syncRoles([$role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }
}
