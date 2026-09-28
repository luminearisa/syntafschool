<?php

namespace Tests\Feature\Lms;

use App\Models\Guardian;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Modules\LMS\Models\LmsQuizAttempt;

/**
 * Keamanan aktivitas (§54): isolasi data siswa, larangan menilai, batas wali,
 * dan perlindungan percobaan kuis.
 */
class LmsActivitySecurityTest extends LmsTestCase
{
    /** @return array{0: \App\Modules\LMS\Models\LmsActivity, 1: array<string, mixed>} */
    protected function assignmentWorld(array $attributes = []): array
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();

        $activity = $this->makeActivity($section, array_merge([
            'activity_type' => 'ASSIGNMENT',
            'title' => 'Tugas Keamanan',
            'submission_type' => 'ONLINE_TEXT',
            'due_at' => now()->addDay()->toDateTimeString(),
        ], $attributes));

        $this->publishActivity($activity);

        return [$activity->fresh(), $world];
    }

    /** Siswa tidak dapat melihat pengumpulan siswa lain (§54). */
    public function test_siswa_tidak_dapat_melihat_pengumpulan_siswa_lain(): void
    {
        [$activity, $world] = $this->assignmentWorld();

        $this->enroll($activity->course, $world['studentUsers'][0]);
        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $submission = $this->postJson("/api/v1/lms/activities/{$activity->id}/submissions/submit", [
            'text_content' => 'Jawaban rahasia siswa 1',
        ])->assertOk()->json('data');

        // Siswa kedua.
        $this->enroll($activity->course, $world['studentUsers'][1]);
        $this->actingAsStudent($world['students'][1], $world['studentUsers'][1]);

        $this->getJson("/api/v1/lms/submissions/{$submission['id']}")->assertForbidden();

        $history = $this->getJson("/api/v1/lms/activities/{$activity->id}/submissions")->assertOk()->json('data');

        $this->assertSame([], $history, 'Siswa hanya melihat pengumpulannya sendiri.');
    }

    /** Siswa tidak dapat menilai atau menyentuh endpoint guru (§54). */
    public function test_siswa_tidak_dapat_menilai(): void
    {
        [$activity, $world] = $this->assignmentWorld();

        $this->enroll($activity->course, $world['studentUsers'][0]);
        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $submission = $this->postJson("/api/v1/lms/activities/{$activity->id}/submissions/submit", [
            'text_content' => 'Jawaban',
        ])->assertOk()->json('data');

        $this->postJson("/api/v1/lms/submissions/{$submission['id']}/grade", ['grade' => 100])->assertForbidden();
        $this->getJson("/api/v1/lms/activities/{$activity->id}/assignment/overview")->assertForbidden();

        // Siswa juga tidak dapat mengubah nilainya lewat endpoint nilai.
        $this->postJson("/api/v1/lms/activities/{$activity->id}/grades", [
            'user_id' => $world['studentUsers'][0]->id,
            'grade' => 100,
        ])->assertForbidden();
    }

    /** Wali murid hanya membaca: tidak submit, tidak menjawab, tidak menilai (§48). */
    public function test_wali_hanya_membaca(): void
    {
        [$activity, $world] = $this->assignmentWorld();

        $student = $world['students'][0];
        $studentUser = $world['studentUsers'][0];
        $this->enroll($activity->course, $studentUser);

        $this->actingAsStudent($student, $studentUser);
        $this->postJson("/api/v1/lms/activities/{$activity->id}/submissions/submit", ['text_content' => 'Jawaban'])
            ->assertOk();

        $guardian = Guardian::create(['full_name' => 'Wali Siswa', 'is_active' => true]);
        StudentGuardian::create(['guardian_id' => $guardian->id, 'student_id' => $student->id, 'relation' => 'ayah']);

        $this->actingAsParent($guardian);

        $this->postJson("/api/v1/lms/activities/{$activity->id}/submissions/submit", ['text_content' => 'Menyamar'])
            ->assertForbidden();

        $this->postJson("/api/v1/lms/activities/{$activity->id}/grades", [
            'user_id' => $studentUser->id,
            'grade' => 100,
        ])->assertForbidden();

        // Wali tetap dapat melihat nilai anaknya (§48).
        $this->getJson("/api/v1/lms/courses/{$activity->course_id}/grades?user_id={$studentUser->id}")
            ->assertOk()
            ->assertJsonPath('data.read_only', true);
    }

    /** Guru course lain tidak dapat menilai aktivitas ini (§54). */
    public function test_guru_course_lain_tidak_dapat_menilai(): void
    {
        [$activity, $world] = $this->assignmentWorld();

        $studentUser = $world['studentUsers'][0];
        $this->enroll($activity->course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $submission = $this->postJson("/api/v1/lms/activities/{$activity->id}/submissions/submit", ['text_content' => 'Jawaban'])
            ->assertOk()->json('data');

        // Guru lain: akun guru tanpa kaitan dengan course ini.
        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->syncRoles(['guru']);
        \Laravel\Sanctum\Sanctum::actingAs($outsider);

        $this->postJson("/api/v1/lms/submissions/{$submission['id']}/grade", ['grade' => 90])->assertForbidden();
    }

    /** Siswa tidak dapat menjawab atau mengirim percobaan kuis orang lain (§54). */
    public function test_siswa_tidak_dapat_menyentuh_percobaan_siswa_lain(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();

        $activity = $this->makeActivity($section, ['activity_type' => 'QUIZ', 'title' => 'Kuis Keamanan']);
        $question = $this->makeQuestion(['course_id' => $course->id], $course);

        app(\App\Modules\LMS\Application\Services\QuizService::class)->addQuestions(
            app(\App\Modules\LMS\Application\Services\QuizService::class)->ensureConfig($activity),
            [['question_id' => $question->id, 'marks' => 1]],
        );

        $this->publishActivity($activity);

        $this->enroll($course, $world['studentUsers'][0]);
        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $attempt = $this->postJson("/api/v1/lms/activities/{$activity->id}/quiz/attempts")
            ->assertCreated()->json('data');

        $this->enroll($course, $world['studentUsers'][1]);
        $this->actingAsStudent($world['students'][1], $world['studentUsers'][1]);

        $this->getJson("/api/v1/lms/attempts/{$attempt['attempt']['id']}")->assertForbidden();

        $this->postJson("/api/v1/lms/attempts/{$attempt['attempt']['id']}/answers", [
            'question_id' => $attempt['questions'][0]['question_id'],
            'answer_data' => ['option_id' => 1],
        ])->assertForbidden();

        $this->postJson("/api/v1/lms/attempts/{$attempt['attempt']['id']}/submit")->assertForbidden();
    }

    /** Wali dapat melihat daftar percobaan kuis anaknya, tanpa dapat mengerjakan (§48). */
    public function test_wali_dapat_melihat_percobaan_anak(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();

        $activity = $this->makeActivity($section, ['activity_type' => 'QUIZ', 'title' => 'Kuis Wali']);
        $question = $this->makeQuestion(['course_id' => $course->id], $course);

        app(\App\Modules\LMS\Application\Services\QuizService::class)->addQuestions(
            app(\App\Modules\LMS\Application\Services\QuizService::class)->ensureConfig($activity),
            [['question_id' => $question->id, 'marks' => 1]],
        );

        $this->publishActivity($activity);

        $student = $world['students'][0];
        $studentUser = $world['studentUsers'][0];
        $this->enroll($course, $studentUser);
        $this->actingAsStudent($student, $studentUser);

        $attempt = $this->postJson("/api/v1/lms/activities/{$activity->id}/quiz/attempts")
            ->assertCreated()->json('data');

        $correct = collect($attempt['questions'][0]['options'])->firstWhere('text', '4');

        $this->postJson("/api/v1/lms/attempts/{$attempt['attempt']['id']}/answers", [
            'question_id' => $attempt['questions'][0]['question_id'],
            'answer_data' => ['option_id' => $correct['id']],
        ])->assertOk();

        $this->postJson("/api/v1/lms/attempts/{$attempt['attempt']['id']}/submit")->assertOk();

        $guardian = Guardian::create(['full_name' => 'Wali Kuis', 'is_active' => true]);
        StudentGuardian::create(['guardian_id' => $guardian->id, 'student_id' => $student->id, 'relation' => 'ibu']);

        $this->actingAsParent($guardian);

        $this->getJson("/api/v1/lms/activities/{$activity->id}/quiz/student-attempts/{$studentUser->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Wali tidak dapat memulai percobaan kuis.
        $this->postJson("/api/v1/lms/activities/{$activity->id}/quiz/attempts")->assertForbidden();
    }

    /** Guest tidak dapat membuka endpoint aktivitas yang butuh autentikasi. */
    public function test_guest_tidak_dapat_mengakses_aktivitas(): void
    {
        [$activity] = $this->assignmentWorld();

        $this->getJson("/api/v1/lms/activities/{$activity->id}")->assertUnauthorized();
        $this->postJson("/api/v1/lms/activities/{$activity->id}/submissions/submit", ['text_content' => 'x'])
            ->assertUnauthorized();
    }

    /** Aktivitas draf tidak dapat dikerjakan siswa walau ia terdaftar (§41). */
    public function test_aktivitas_draf_tidak_dapat_dikerjakan(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();

        $activity = $this->makeActivity($section, [
            'activity_type' => 'ASSIGNMENT',
            'submission_type' => 'ONLINE_TEXT',
        ]);

        $this->enroll($course, $world['studentUsers'][0]);
        $this->actingAsStudent($world['students'][0], $world['studentUsers'][0]);

        $this->getJson("/api/v1/lms/activities/{$activity->id}/assignment")
            ->assertOk()
            ->assertJsonPath('data.locked', true)
            ->assertJsonPath('data.state.reason', 'not_published');

        $this->postJson("/api/v1/lms/activities/{$activity->id}/submissions/submit", ['text_content' => 'x'])
            ->assertForbidden();
    }

    /** Prasyarat nilai: kuis kedua terkunci sampai kuis pertama lulus (§41). */
    public function test_prasyarat_aktivitas_mengunci_aktivitas_berikutnya(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();

        $first = $this->makeActivity($section, [
            'activity_type' => 'QUIZ',
            'title' => 'Kuis 1',
            'passing_grade' => 70,
        ]);

        $question = $this->makeQuestion(['course_id' => $course->id], $course);
        app(\App\Modules\LMS\Application\Services\QuizService::class)->addQuestions(
            app(\App\Modules\LMS\Application\Services\QuizService::class)->ensureConfig($first),
            [['question_id' => $question->id, 'marks' => 1]],
        );

        $second = $this->makeActivity($section, [
            'activity_type' => 'QUIZ',
            'title' => 'Kuis 2',
            'availability' => ['required_activity_ids' => [$first->id], 'required_activity_grade' => 70],
        ]);

        app(\App\Modules\LMS\Application\Services\QuizService::class)->addQuestions(
            app(\App\Modules\LMS\Application\Services\QuizService::class)->ensureConfig($second),
            [['question_id' => $question->id, 'marks' => 1]],
        );

        $this->publishActivity($first);
        $this->publishActivity($second);

        $studentUser = $world['studentUsers'][0];
        $this->enroll($course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        // Kuis 2 terkunci sebelum Kuis 1 selesai.
        $locked = $this->getJson("/api/v1/lms/activities/{$second->id}/quiz")->assertOk()->json('data');

        $this->assertTrue($locked['locked']);
        $this->assertSame('required_activity_incomplete', $locked['state']['reason']);

        $this->postJson("/api/v1/lms/activities/{$second->id}/quiz/attempts")->assertForbidden();

        // Kerjakan Kuis 1 dengan benar.
        $attempt = $this->postJson("/api/v1/lms/activities/{$first->id}/quiz/attempts")
            ->assertCreated()->json('data');

        $correct = collect($attempt['questions'][0]['options'])->firstWhere('text', '4');

        $this->postJson("/api/v1/lms/attempts/{$attempt['attempt']['id']}/answers", [
            'question_id' => $attempt['questions'][0]['question_id'],
            'answer_data' => ['option_id' => $correct['id']],
        ])->assertOk();

        $this->postJson("/api/v1/lms/attempts/{$attempt['attempt']['id']}/submit")->assertOk();

        // Kuis 2 terbuka setelah syarat terpenuhi.
        $open = $this->getJson("/api/v1/lms/activities/{$second->id}/quiz")->assertOk()->json('data');
        $this->assertFalse($open['locked']);
    }

    /** Percobaan ditolak bila kuota percobaan habis — divalidasi server (§54). */
    public function test_proteksi_percobaan_kuis_divalidasi_server(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();

        $activity = $this->makeActivity($section, [
            'activity_type' => 'QUIZ',
            'title' => 'Kuis Kuota',
            'attempts_allowed' => 1,
        ]);

        $question = $this->makeQuestion(['course_id' => $course->id], $course);
        app(\App\Modules\LMS\Application\Services\QuizService::class)->addQuestions(
            app(\App\Modules\LMS\Application\Services\QuizService::class)->ensureConfig($activity),
            [['question_id' => $question->id, 'marks' => 1]],
        );

        $this->publishActivity($activity);

        $studentUser = $world['studentUsers'][0];
        $this->enroll($course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $attempt = $this->postJson("/api/v1/lms/activities/{$activity->id}/quiz/attempts")
            ->assertCreated()->json('data');

        $this->postJson("/api/v1/lms/attempts/{$attempt['attempt']['id']}/submit")->assertOk();

        // Percobaan diselesaikan langsung di database (menyamar sebagai klien nakal).
        LmsQuizAttempt::query()->whereKey($attempt['attempt']['id'])->update(['status' => 'GRADED']);

        $this->postJson("/api/v1/lms/activities/{$activity->id}/quiz/attempts")->assertStatus(422);
    }
}
