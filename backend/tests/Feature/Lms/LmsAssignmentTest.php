<?php

namespace Tests\Feature\Lms;

use App\Models\User;
use App\Modules\LMS\Application\Services\AssignmentGradingService;
use App\Modules\LMS\Application\Services\AssignmentSubmissionService;
use App\Modules\LMS\Models\LmsAssignmentSubmission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Tugas: draf, pengiriman, pengiriman ulang, keterlambatan, cutoff, berkas,
 * penilaian, umpan balik, dan akses wali yang hanya membaca (§4–§11, §48).
 */
class LmsAssignmentTest extends LmsTestCase
{
    /** Alur guru → siswa → guru untuk satu tugas (§3, §56). */
    public function test_alur_tugas_lengkap_dari_draf_sampai_nilai(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();
        $student = $world['students'][0];
        $studentUser = $world['studentUsers'][0];

        $activity = $this->makeActivity($section, [
            'activity_type' => 'ASSIGNMENT',
            'title' => 'Latihan Aljabar 1',
            'submission_type' => 'ONLINE_TEXT_AND_FILE',
            'require_text' => true,
            'maximum_grade' => 100,
            'passing_grade' => 70,
            'due_at' => now()->addDay()->toDateTimeString(),
        ]);

        $this->publishActivity($activity);
        $this->enroll($course, $studentUser);

        // -------- siswa menyimpan draf
        $this->actingAsStudent($student, $studentUser);

        $this->postJson("/api/v1/lms/activities/{$activity->id}/submissions/draft", [
            'text_content' => 'Jawaban sementara',
        ])->assertOk()->assertJsonPath('data.status', 'DRAFT');

        // -------- siswa mengirim tugas
        $submission = $this->postJson("/api/v1/lms/activities/{$activity->id}/submissions/submit", [
            'text_content' => 'x = 4',
        ])->assertOk()->json('data');

        $this->assertSame('SUBMITTED', $submission['status']);
        $this->assertFalse($submission['late']);

        $submissionId = $submission['id'];

        // Submission tercatat pada struktur penyelesaian course (§40).
        $this->assertDatabaseHas('lms_activity_completions', [
            'user_id' => $studentUser->id,
            'learning_item_id' => $activity->learning_item_id,
            'status' => 'COMPLETE',
        ]);

        // -------- guru menilai + umpan balik
        $teacherUser = $this->actingAsTeacher($world['teacher']);

        $this->postJson("/api/v1/lms/submissions/{$submissionId}/grade", [
            'grade' => 85,
            'feedback' => 'Langkah pengerjaan sudah runtut.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'GRADED')
            ->assertJsonPath('data.grade', 85)
            ->assertJsonPath('data.passed', true);

        $this->assertDatabaseHas('lms_activity_grades', [
            'activity_id' => $activity->id,
            'user_id' => $studentUser->id,
            'grade' => 85,
        ]);

        // -------- siswa melihat nilai & umpan balik
        $this->actingAsStudent($student, $studentUser);

        $this->getJson("/api/v1/lms/submissions/{$submissionId}")
            ->assertOk()
            ->assertJsonPath('data.grade', 85)
            ->assertJsonPath('data.feedback', 'Langkah pengerjaan sudah runtut.');

        // -------- wali murid hanya membaca (§48)
        $guardian = \App\Models\Guardian::create(['full_name' => 'Wali Siswa 1', 'is_active' => true]);
        \App\Models\StudentGuardian::create([
            'guardian_id' => $guardian->id,
            'student_id' => $student->id,
            'relation' => 'ayah',
        ]);

        $this->actingAsParent($guardian);

        $this->getJson("/api/v1/lms/activities/{$activity->id}/assignment?user_id={$studentUser->id}")
            ->assertForbidden();

        $this->getJson("/api/v1/lms/activities/{$activity->id}/grade?user_id={$studentUser->id}")
            ->assertOk()
            ->assertJsonPath('data.grade.grade', 85)
            ->assertJsonPath('data.read_only', true);

        // Wali tidak boleh mengirim tugas.
        $this->postJson("/api/v1/lms/activities/{$activity->id}/submissions/submit", ['text_content' => 'x'])
            ->assertForbidden();
    }

    /** Pengiriman ulang membuat attempt baru, riwayat lama tetap ada (§6). */
    public function test_pengiriman_ulang_tidak_menimpa_riwayat(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();
        $student = $world['students'][0];
        $studentUser = $world['studentUsers'][0];

        $activity = $this->makeActivity($section, [
            'activity_type' => 'ASSIGNMENT',
            'maximum_attempts' => 3,
            'submission_type' => 'ONLINE_TEXT',
            'due_at' => now()->addDay()->toDateTimeString(),
        ]);

        $this->publishActivity($activity);
        $this->enroll($course, $studentUser);
        $this->actingAsStudent($student, $studentUser);

        $first = $this->postJson("/api/v1/lms/activities/{$activity->id}/submissions/submit", ['text_content' => 'Versi 1'])
            ->assertOk()->json('data');

        $second = $this->postJson("/api/v1/lms/activities/{$activity->id}/submissions/submit", ['text_content' => 'Versi 2'])
            ->assertOk()->json('data');

        $this->assertSame(1, $first['attempt']);
        $this->assertSame(2, $second['attempt']);
        $this->assertSame('RESUBMITTED', $second['status']);

        $this->assertDatabaseCount('lms_assignment_submissions', 2);
        $this->assertDatabaseHas('lms_assignment_submissions', ['id' => $first['id'], 'text_content' => 'Versi 1']);
    }

    /** Keterlambatan ditandai dan penalti menyimpan nilai asli (§9). */
    public function test_pengumpulan_terlambat_dan_penalti_menyimpan_nilai_asli(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();
        $student = $world['students'][0];
        $studentUser = $world['studentUsers'][0];

        $activity = $this->makeActivity($section, [
            'activity_type' => 'ASSIGNMENT',
            'submission_type' => 'ONLINE_TEXT',
            'late_penalty_percent' => 10,
            'allow_late_submission' => true,
            'due_at' => now()->subHours(5)->toDateTimeString(),
            'close_at' => now()->addDay()->toDateTimeString(),
        ]);

        $this->publishActivity($activity);
        $this->enroll($course, $studentUser);
        $this->actingAsStudent($student, $studentUser);

        $submission = $this->postJson("/api/v1/lms/activities/{$activity->id}/submissions/submit", ['text_content' => 'Terlambat'])
            ->assertOk()->json('data');

        $this->assertTrue($submission['late']);

        $teacherUser = $this->actingAsTeacher($world['teacher']);

        $graded = $this->postJson("/api/v1/lms/submissions/{$submission['id']}/grade", ['grade' => 90])
            ->assertOk()->json('data');

        // 90 - 10% = 81, nilai asli tetap tersimpan.
        $this->assertSame(81.0, (float) $graded['grade']);
        $this->assertSame(90.0, (float) $graded['original_grade']);
        $this->assertSame(10.0, (float) $graded['late_penalty_percent']);
    }

    /** Cutoff keras: aktivitas tertutup tidak dapat dikerjakan (§9, §41). */
    public function test_pengumpulan_setelah_cutoff_ditolak(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();
        $studentUser = $world['studentUsers'][0];

        $activity = $this->makeActivity($section, [
            'activity_type' => 'ASSIGNMENT',
            'submission_type' => 'ONLINE_TEXT',
            'allow_late_submission' => true,
            'due_at' => now()->subDays(3)->toDateTimeString(),
            'close_at' => now()->subDay()->toDateTimeString(),
        ]);

        $this->publishActivity($activity);
        $this->enroll($course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $this->postJson("/api/v1/lms/activities/{$activity->id}/submissions/submit", ['text_content' => 'Terlambat sekali'])
            ->assertForbidden();

        $this->getJson("/api/v1/lms/activities/{$activity->id}/submission-status")
            ->assertOk()
            ->assertJsonPath('data.status', 'DRAFT');
    }

    /** Lapisan service juga menolak pengumpulan setelah cutoff (§9, §54). */
    public function test_layanan_menolak_pengumpulan_setelah_cutoff(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();
        $studentUser = $world['studentUsers'][0];

        $activity = $this->makeActivity($section, [
            'activity_type' => 'ASSIGNMENT',
            'submission_type' => 'ONLINE_TEXT',
            'allow_late_submission' => true,
            'due_at' => now()->subDays(3)->toDateTimeString(),
            'close_at' => now()->subDay()->toDateTimeString(),
        ]);

        $this->publishActivity($activity);
        $enrollment = $this->enroll($course, $studentUser);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(AssignmentSubmissionService::class)->submit(
            $activity->fresh(),
            $enrollment,
            $studentUser->id,
            ['text_content' => 'Terlambat'],
        );
    }

    /** Bila keterlambatan tidak diizinkan, pengumpulan setelah tenggat ditolak. */
    public function test_keterlambatan_yang_tidak_diizinkan_ditolak(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();
        $studentUser = $world['studentUsers'][0];

        $activity = $this->makeActivity($section, [
            'activity_type' => 'ASSIGNMENT',
            'submission_type' => 'ONLINE_TEXT',
            'allow_late_submission' => false,
            'due_at' => now()->subHour()->toDateTimeString(),
        ]);

        $this->publishActivity($activity);
        $this->enroll($course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $this->postJson("/api/v1/lms/activities/{$activity->id}/submissions/submit", ['text_content' => 'Lewat'])
            ->assertStatus(422);
    }

    /** Berkas submission: tipe divalidasi, unduhan ber-otorisasi (§6, §54). */
    public function test_berkas_submission_divalidasi_dan_unduhan_ber_otorisasi(): void
    {
        Storage::fake('local');

        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();
        $student = $world['students'][0];
        $studentUser = $world['studentUsers'][0];

        $activity = $this->makeActivity($section, [
            'activity_type' => 'ASSIGNMENT',
            'submission_type' => 'FILE',
            'require_file' => true,
            'allowed_file_types' => ['PDF'],
            'due_at' => now()->addDay()->toDateTimeString(),
        ]);

        $this->publishActivity($activity);
        $this->enroll($course, $studentUser);
        $this->actingAsStudent($student, $studentUser);

        $draft = $this->postJson("/api/v1/lms/activities/{$activity->id}/submissions/draft", [])
            ->assertOk()->json('data');

        // Unggah PDF → diterima.
        $this->postJson("/api/v1/lms/submissions/{$draft['id']}/files", [
            'file' => UploadedFile::fake()->create('tugas.pdf', 120, 'application/pdf'),
        ])->assertCreated();

        // Unggah DOCX → ditolak karena hanya PDF yang diizinkan.
        $this->postJson("/api/v1/lms/submissions/{$draft['id']}/files", [
            'file' => UploadedFile::fake()->create('tugas.docx', 80, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ])->assertStatus(422);

        $submitted = $this->postJson("/api/v1/lms/activities/{$activity->id}/submissions/submit", [])
            ->assertOk()->json('data');

        $fileId = $submitted['files'][0]['id'];

        // Siswa pemilik boleh mengunduh.
        $this->get("/api/v1/lms/submission-files/{$fileId}")->assertOk();

        // Guru penilai boleh mengunduh.
        $this->actingAsTeacher($world['teacher']);
        $this->get("/api/v1/lms/submission-files/{$fileId}")->assertOk();

        // Siswa lain tidak boleh.
        $this->actingAsStudent($world['students'][1], $world['studentUsers'][1]);
        $this->get("/api/v1/lms/submission-files/{$fileId}")->assertForbidden();
    }

    /** Guru tidak dapat mengubah isi pengumpulan siswa (§7 padanan §54). */
    public function test_guru_tidak_dapat_mengubah_isi_pengumpulan(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();
        $student = $world['students'][0];
        $studentUser = $world['studentUsers'][0];

        $activity = $this->makeActivity($section, [
            'activity_type' => 'ASSIGNMENT',
            'submission_type' => 'ONLINE_TEXT',
            'due_at' => now()->addDay()->toDateTimeString(),
        ]);

        $this->publishActivity($activity);
        $this->enroll($course, $studentUser);
        $this->actingAsStudent($student, $studentUser);

        $submission = $this->postJson("/api/v1/lms/activities/{$activity->id}/submissions/submit", ['text_content' => 'Jawaban siswa'])
            ->assertOk()->json('data');

        $this->actingAsTeacher($world['teacher']);

        // Unggah berkas ke submission siswa lain tidak diizinkan.
        $this->postJson("/api/v1/lms/submissions/{$submission['id']}/files", [
            'file' => UploadedFile::fake()->create('ubah.pdf', 10, 'application/pdf'),
        ])->assertForbidden();
    }

    /** Statistik tugas untuk guru (§11). */
    public function test_statistik_tugas_menghitung_pengumpulan_dan_nilai(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();

        $activity = $this->makeActivity($section, [
            'activity_type' => 'ASSIGNMENT',
            'submission_type' => 'ONLINE_TEXT',
            'due_at' => now()->addDay()->toDateTimeString(),
        ]);

        $this->publishActivity($activity);

        foreach ($world['studentUsers'] as $index => $user) {
            $this->enroll($course, $user);
            $this->actingAsStudent($world['students'][$index], $user);
            $this->postJson("/api/v1/lms/activities/{$activity->id}/submissions/submit", [
                'text_content' => 'Jawaban siswa '.$index,
            ])->assertOk();
        }

        $this->actingAsTeacher($world['teacher']);

        $statistics = $this->getJson("/api/v1/lms/activities/{$activity->id}/assignment/statistics")
            ->assertOk()->json('data.statistics');

        $this->assertSame(3, $statistics['participants']);
        $this->assertSame(3, $statistics['submitted']);
        $this->assertSame(0, $statistics['graded']);
        $this->assertSame(100.0, (float) $statistics['submission_rate']);
    }

    /** Duplikasi tugas menyalin konfigurasi tanpa riwayat (§44). */
    public function test_duplikasi_tugas_tidak_menyalin_riwayat(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();
        $student = $world['students'][0];
        $studentUser = $world['studentUsers'][0];

        $activity = $this->makeActivity($section, [
            'activity_type' => 'ASSIGNMENT',
            'title' => 'Latihan Aljabar 1',
            'submission_type' => 'ONLINE_TEXT',
            'due_at' => now()->addDay()->toDateTimeString(),
        ]);

        $this->publishActivity($activity);
        $this->enroll($course, $studentUser);
        $this->actingAsStudent($student, $studentUser);

        $this->postJson("/api/v1/lms/activities/{$activity->id}/submissions/submit", ['text_content' => 'Jawaban'])
            ->assertOk();

        $teacherUser = $this->actingAsTeacher($world['teacher']);

        $copy = $this->postJson("/api/v1/lms/activities/{$activity->id}/duplicate", [
            'title' => 'Latihan Aljabar 2',
        ])->assertCreated()->json('data');

        $this->assertSame('Latihan Aljabar 2', $copy['title']);
        $this->assertSame('DRAFT', $copy['status']);
        $this->assertSame('ONLINE_TEXT', $copy['detail']['submission_type']);

        // Konfigurasi disalin, riwayat & nilai tidak (§44).
        $copyAssignment = \App\Modules\LMS\Models\LmsAssignment::query()
            ->where('activity_id', $copy['id'])
            ->firstOrFail();

        $this->assertSame(0, $copyAssignment->submissions()->count());
        $this->assertSame(1, $activity->assignment()->first()->submissions()->count());
    }

    /** Validasi nilai: 0 ≤ nilai ≤ maximum_grade (§8). */
    public function test_nilai_di_luar_rentang_ditolak(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();
        $student = $world['students'][0];
        $studentUser = $world['studentUsers'][0];

        $activity = $this->makeActivity($section, [
            'activity_type' => 'ASSIGNMENT',
            'submission_type' => 'ONLINE_TEXT',
            'maximum_grade' => 100,
            'due_at' => now()->addDay()->toDateTimeString(),
        ]);

        $this->publishActivity($activity);
        $this->enroll($course, $studentUser);
        $this->actingAsStudent($student, $studentUser);

        $submission = $this->postJson("/api/v1/lms/activities/{$activity->id}/submissions/submit", ['text_content' => 'x'])
            ->assertOk()->json('data');

        $this->actingAsTeacher($world['teacher']);

        $this->postJson("/api/v1/lms/submissions/{$submission['id']}/grade", ['grade' => 120])
            ->assertStatus(422);

        $this->postJson("/api/v1/lms/submissions/{$submission['id']}/grade", ['grade' => -5])
            ->assertStatus(422);
    }
}
