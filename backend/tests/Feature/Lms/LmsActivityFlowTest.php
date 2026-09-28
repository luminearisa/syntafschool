<?php

namespace Tests\Feature\Lms;

use App\Models\Guardian;
use App\Models\StudentGuardian;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Uji alur menyeluruh Phase 2 (§56).
 *
 * Setiap berkas berjalan seperti pemakaian nyata: guru membuat & menerbitkan
 * aktivitas lewat API, siswa mengerjakan, guru menilai, wali memantau — dan
 * memastikan setiap aktivitas tetap terhubung ke course → enrollment →
 * submission/attempt → nilai → penyelesaian → progres.
 */
class LmsActivityFlowTest extends LmsTestCase
{
    /** Guru → Assignment → Submission → Grading → Feedback → Wali (§56). */
    public function test_alur_tugas_end_to_end(): void
    {
        Storage::fake('local');

        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();

        // ---------------- Guru membuat tugas lewat API
        $teacherUser = $this->actingAsTeacher($world['teacher']);

        $activity = $this->postJson("/api/v1/lms/sections/{$section->id}/activities", [
            'activity_type' => 'ASSIGNMENT',
            'title' => 'Esai Ketahanan Pangan',
            'description' => 'Tulis esai singkat.',
            'instructions' => '<p>Minimal 200 kata.</p>',
            'due_at' => now()->addDays(3)->toDateTimeString(),
            'close_at' => now()->addDays(5)->toDateTimeString(),
        ])->assertCreated()->json('data');

        $activityId = $activity['id'];

        $this->assertSame('DRAFT', $activity['status']);
        $this->assertSame('ASSIGNMENT', $activity['activity_type']);

        // Konfigurasi tugas (tab Grading/Settings wizard).
        $this->putJson("/api/v1/lms/activities/{$activityId}/assignment", [
            'submission_type' => 'ONLINE_TEXT_AND_FILE',
            'require_text' => true,
            'require_file' => true,
            'maximum_grade' => 100,
            'passing_grade' => 70,
            'allowed_file_types' => ['PDF'],
        ])->assertOk()->assertJsonPath('data.require_file', true);

        // Terbitkan → siswa melihat di silabus.
        $this->postJson("/api/v1/lms/activities/{$activityId}/publish")->assertOk();

        $list = $this->getJson("/api/v1/lms/courses/{$course->id}/activities")->assertOk()->json('data');
        $this->assertCount(1, $list);

        // ---------------- Siswa mengerjakan
        $student = $world['students'][0];
        $studentUser = $world['studentUsers'][0];
        $this->enroll($course, $studentUser);
        $this->actingAsStudent($student, $studentUser);

        $view = $this->getJson("/api/v1/lms/activities/{$activityId}/assignment")->assertOk()->json('data');

        $this->assertFalse($view['locked']);
        $this->assertSame('NOT_SUBMITTED', $view['status']);
        $this->assertSame(1, $view['attempts_remaining']);

        $draft = $this->postJson("/api/v1/lms/activities/{$activityId}/submissions/draft", [
            'text_content' => 'Draf esai saya.',
        ])->assertOk()->json('data');

        $this->postJson("/api/v1/lms/submissions/{$draft['id']}/files", [
            'file' => UploadedFile::fake()->create('esai.pdf', 200, 'application/pdf'),
        ])->assertCreated();

        $submission = $this->postJson("/api/v1/lms/activities/{$activityId}/submissions/submit", [
            'text_content' => 'Esai final tentang ketahanan pangan.',
        ])->assertOk()->json('data');

        $this->assertSame('SUBMITTED', $submission['status']);
        $this->assertFalse($submission['late']);

        // ---------------- Guru menilai
        \Laravel\Sanctum\Sanctum::actingAs($teacherUser);

        $overview = $this->getJson("/api/v1/lms/activities/{$activityId}/assignment/overview")
            ->assertOk()->json('data');

        $this->assertSame(1, $overview['statistics']['submitted']);
        $this->assertSame(1, $overview['statistics']['ungraded']);
        $this->assertCount(1, $overview['submissions']['data']);

        $this->postJson("/api/v1/lms/submissions/{$submission['id']}/grade", [
            'grade' => 88,
            'feedback' => 'Struktur argumen baik, perkuat data.',
        ])->assertOk()->assertJsonPath('data.grade', 88);

        // ---------------- Siswa menerima hasil
        $this->actingAsStudent($student, $studentUser);

        $result = $this->getJson("/api/v1/lms/submissions/{$submission['id']}")->assertOk()->json('data');

        $this->assertSame(88.0, (float) $result['grade']);
        $this->assertTrue($result['passed']);
        $this->assertSame('Struktur argumen baik, perkuat data.', $result['feedback']);

        // Nilai masuk struktur nilai LMS (§46) dan penyelesaian tercatat (§40).
        $this->assertDatabaseHas('lms_activity_grades', [
            'activity_id' => $activityId,
            'user_id' => $studentUser->id,
            'source' => 'ASSIGNMENT',
        ]);

        $grades = $this->getJson("/api/v1/lms/courses/{$course->id}/grades")->assertOk()->json('data.summary');

        $this->assertSame(1, $grades['graded']);
        $this->assertSame(88.0, (float) $grades['average']);

        // ---------------- Wali memantau (read-only)
        $guardian = Guardian::create(['full_name' => 'Wali Esai', 'is_active' => true]);
        StudentGuardian::create(['guardian_id' => $guardian->id, 'student_id' => $student->id, 'relation' => 'ayah']);

        $this->actingAsParent($guardian);

        $childGrades = $this->getJson("/api/v1/lms/courses/{$course->id}/grades?user_id={$studentUser->id}")
            ->assertOk()->json('data');

        $this->assertTrue($childGrades['read_only']);
        $this->assertSame(88.0, (float) $childGrades['summary']['average']);

        $this->postJson("/api/v1/lms/activities/{$activityId}/submissions/submit", ['text_content' => 'Menyamar'])
            ->assertForbidden();
    }

    /** Bank soal → Kuis → Attempt → Auto grade → Completion (§56). */
    public function test_alur_kuis_end_to_end(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();

        $teacherUser = $this->actingAsTeacher($world['teacher']);

        // ---------------- Bank soal + kategori + soal
        $bank = $this->postJson('/api/v1/lms/question-banks', [
            'name' => 'Bank IPA',
            'course_id' => $course->id,
        ])->assertCreated()->json('data');

        $category = $this->postJson("/api/v1/lms/question-banks/{$bank['id']}/categories", ['name' => 'Biologi'])
            ->assertCreated()->json('data.categories.0');

        $question = $this->postJson('/api/v1/lms/questions', [
            'bank_id' => $bank['id'],
            'category_id' => $category['id'],
            'course_id' => $course->id,
            'question_type' => 'MULTIPLE_CHOICE_SINGLE',
            'question_text' => 'Organ pernapasan manusia adalah?',
            'default_mark' => 4,
            'options' => [
                ['option_key' => 'A', 'text' => 'Paru-paru', 'is_correct' => true],
                ['option_key' => 'B', 'text' => 'Lambung', 'is_correct' => false],
                ['option_key' => 'C', 'text' => 'Ginjal', 'is_correct' => false],
            ],
        ])->assertCreated()->json('data');

        // ---------------- Aktivitas kuis + soal disalin dari bank
        $activity = $this->postJson("/api/v1/lms/sections/{$section->id}/activities", [
            'activity_type' => 'QUIZ',
            'title' => 'Kuis Sistem Pernapasan',
            'due_at' => now()->addDay()->toDateTimeString(),
        ])->assertCreated()->json('data');

        $this->putJson("/api/v1/lms/activities/{$activity['id']}/quiz", [
            'time_limit_minutes' => 15,
            'attempts_allowed' => 1,
            'maximum_grade' => 100,
            'passing_grade' => 70,
            'review_options' => ['show_score' => true, 'show_correct_answer' => true, 'show_feedback' => true],
        ])->assertOk();

        $this->postJson("/api/v1/lms/activities/{$activity['id']}/quiz/questions", [
            'questions' => [['question_id' => $question['id'], 'marks' => 4]],
        ])->assertOk()->assertJsonPath('data.total_marks', 4);

        $this->postJson("/api/v1/lms/activities/{$activity['id']}/publish")->assertOk();

        // ---------------- Siswa mengerjakan kuis
        $studentUser = $world['studentUsers'][0];
        $this->enroll($course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $attempt = $this->postJson("/api/v1/lms/activities/{$activity['id']}/quiz/attempts")
            ->assertCreated()->json('data');

        $this->assertCount(1, $attempt['questions']);
        $this->assertNull($attempt['questions'][0]['correct_option_ids']);

        $correct = collect($attempt['questions'][0]['options'])->firstWhere('text', 'Paru-paru');

        $this->postJson("/api/v1/lms/attempts/{$attempt['attempt']['id']}/answers", [
            'question_id' => $attempt['questions'][0]['question_id'],
            'answer_data' => ['option_id' => $correct['id']],
        ])->assertOk();

        $result = $this->postJson("/api/v1/lms/attempts/{$attempt['attempt']['id']}/submit")
            ->assertOk()->json('data');

        $this->assertSame(100.0, (float) $result['attempt']['final_grade']);
        $this->assertTrue($result['attempt']['passed']);

        // Review terbuka setelah selesai (§29).
        $review = $this->getJson("/api/v1/lms/attempts/{$attempt['attempt']['id']}/result")
            ->assertOk()->json('data');

        $this->assertNotNull($review['questions'][0]['correct_option_ids']);

        // Progres course bergerak setelah kuis selesai (§40).
        $progress = $this->getJson("/api/v1/lms/courses/{$course->id}/progress")->assertOk()->json('data');
        $this->assertGreaterThan(0, (float) $progress['progress_percent'], 'Progres course harus bertambah setelah kuis selesai.');

        // ---------------- Guru memeriksa statistik & nilai
        \Laravel\Sanctum\Sanctum::actingAs($teacherUser);

        $statistics = $this->getJson("/api/v1/lms/activities/{$activity['id']}/quiz/statistics")
            ->assertOk()->json('data');

        $this->assertSame(1, $statistics['attempts']);
        $this->assertSame(100.0, (float) $statistics['pass_rate']);
        $this->assertSame(100.0, (float) $statistics['questions'][0]['correct_percent']);

        // Penyelesaian & nilai otomatis tercatat pada course.
        $this->assertDatabaseHas('lms_activity_completions', [
            'user_id' => $studentUser->id,
            'learning_item_id' => $activity['learning_item_id'],
            'status' => 'COMPLETE',
        ]);

        $this->assertDatabaseHas('lms_activity_grades', [
            'activity_id' => $activity['id'],
            'user_id' => $studentUser->id,
            'source' => 'QUIZ',
        ]);

    }

    /** Forum → Diskusi → Balasan → Moderasi → Notifikasi (§56). */
    public function test_alur_forum_end_to_end(): void
    {
        Notification::fake();

        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();

        $teacherUser = $this->actingAsTeacher($world['teacher']);

        $activity = $this->postJson("/api/v1/lms/sections/{$section->id}/activities", [
            'activity_type' => 'FORUM',
            'title' => 'Forum Tanya Jawab',
        ])->assertCreated()->json('data');

        $this->putJson("/api/v1/lms/activities/{$activity['id']}/forum", [
            'forum_type' => 'Q_AND_A',
            'moderation_enabled' => true,
            'required_posts' => 2,
        ])->assertOk()->assertJsonPath('data.moderation_enabled', true);

        $this->postJson("/api/v1/lms/activities/{$activity['id']}/publish")->assertOk();

        // Guru berlangganan agar menerima notifikasi.
        $this->postJson("/api/v1/lms/activities/{$activity['id']}/forum/subscribe")->assertOk();

        // ---------------- Siswa membuka diskusi (tertahan moderasi)
        $studentUser = $world['studentUsers'][0];
        $this->enroll($course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $discussion = $this->postJson("/api/v1/lms/activities/{$activity['id']}/forum/discussions", [
            'title' => 'Bagaimana proses difusi oksigen?',
            'content' => '<p>Mohon penjelasannya.</p>',
        ])->assertCreated()->json('data');

        $this->assertSame('PENDING', $discussion['status']);

        // ---------------- Guru memoderasi
        \Laravel\Sanctum\Sanctum::actingAs($teacherUser);

        $this->postJson("/api/v1/lms/forum-moderation/discussion/{$discussion['id']}/approve")->assertOk();

        $this->postJson("/api/v1/lms/discussions/{$discussion['id']}/posts", [
            'content' => '<p>Oksigen berdifusi melalui alveolus.</p>',
        ])->assertCreated();

        // ---------------- Siswa membalas → syarat partisipasi terpenuhi
        $this->actingAsStudent($world['students'][0], $studentUser);

        $this->postJson("/api/v1/lms/discussions/{$discussion['id']}/posts", [
            'content' => '<p>Terima kasih, sekarang saya paham.</p>',
        ])->assertCreated();

        // Siswa juga membuka diskusi kedua agar memenuhi required_posts = 2.
        $this->postJson("/api/v1/lms/activities/{$activity['id']}/forum/discussions", [
            'title' => 'Diskusi tambahan',
            'content' => '<p>Pertanyaan lanjutan.</p>',
        ])->assertCreated();

        $this->getJson("/api/v1/lms/activities/{$activity['id']}/forum")
            ->assertOk()
            ->assertJsonPath('data.participation.meets_requirement', true);

        // ---------------- Guru memeriksa statistik forum
        \Laravel\Sanctum\Sanctum::actingAs($teacherUser);

        $statistics = $this->getJson("/api/v1/lms/activities/{$activity['id']}/forum/statistics")
            ->assertOk()->json('data');

        $this->assertSame(2, $statistics['discussions']);
        $this->assertSame(2, $statistics['posts']);
        $this->assertGreaterThanOrEqual(1, $statistics['participating']);
    }

    /** Lesson → Halaman → Cabang → Penyelesaian (§56). */
    public function test_alur_lesson_end_to_end(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();

        $this->actingAsTeacher($world['teacher']);

        $activity = $this->postJson("/api/v1/lms/sections/{$section->id}/activities", [
            'activity_type' => 'LESSON',
            'title' => 'Lesson Fotosintesis',
        ])->assertCreated()->json('data');

        $pageTwo = $this->postJson("/api/v1/lms/activities/{$activity['id']}/lesson/pages", [
            'title' => 'Reaksi Terang',
            'content' => '<p>Reaksi terang terjadi di tilakoid.</p>',
        ])->assertCreated()->json('data');

        $pageOne = $this->postJson("/api/v1/lms/activities/{$activity['id']}/lesson/pages", [
            'title' => 'Pengantar Fotosintesis',
            'content' => '<p>Fotosintesis mengubah cahaya menjadi energi.</p>',
        ])->assertCreated()->json('data');

        // Halaman pengantar diarahkan ke halaman berikutnya secara eksplisit.
        $this->putJson("/api/v1/lms/lesson-pages/{$pageOne['id']}", [
            'sort_order' => 1,
            'branches' => [
                ['label' => 'Lanjut', 'is_correct' => true, 'next_page_id' => $pageTwo['id']],
            ],
        ])->assertOk();

        $this->postJson("/api/v1/lms/activities/{$activity['id']}/publish")->assertOk();

        // ---------------- Siswa belajar
        $studentUser = $world['studentUsers'][0];
        $this->enroll($course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $view = $this->getJson("/api/v1/lms/activities/{$activity['id']}/lesson")->assertOk()->json('data');

        $this->assertCount(2, $view['pages']);

        $first = $this->postJson("/api/v1/lms/activities/{$activity['id']}/lesson/progress", [
            'page_id' => $pageOne['id'],
        ])->assertOk()->json('data');

        $this->assertSame(50.0, (float) $first['progress']['percent']);
        $this->assertSame($pageTwo['id'], $first['next_page']['id']);

        $second = $this->postJson("/api/v1/lms/activities/{$activity['id']}/lesson/progress", [
            'page_id' => $pageTwo['id'],
        ])->assertOk()->json('data');

        $this->assertTrue($second['progress']['completed']);
        $this->assertSame(100.0, (float) $second['progress']['percent']);

        // Penyelesaian & progres course ikut terbarui (§40).
        $this->assertDatabaseHas('lms_activity_completions', [
            'user_id' => $studentUser->id,
            'learning_item_id' => $activity['learning_item_id'],
        ]);

        // Course berisi materi halaman + lesson, sehingga progres bertambah
        // sesuai jumlah item yang benar-benar selesai.
        $progress = $this->getJson("/api/v1/lms/courses/{$course->id}/progress")->assertOk()->json('data');

        $this->assertGreaterThan(0, (float) $progress['progress_percent'], 'Progres course harus bertambah setelah lesson selesai.');
        $this->assertGreaterThanOrEqual(1, (int) $progress['completed_items']);
    }

    /** Dasbor guru & siswa memuat aktivitas Phase 2 (§49–§50). */
    public function test_dasbor_menampilkan_aktivitas_phase_2(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();

        $activity = $this->makeActivity($section, [
            'activity_type' => 'ASSIGNMENT',
            'title' => 'Tugas Dasbor',
            'submission_type' => 'ONLINE_TEXT',
            'due_at' => now()->addDays(2)->toDateTimeString(),
        ]);

        $this->publishActivity($activity);

        $studentUser = $world['studentUsers'][0];
        $this->enroll($course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $dashboard = $this->getJson('/api/v1/lms/dashboard')->assertOk()->json('data');

        $this->assertArrayHasKey('due_soon', $dashboard);
        $this->assertNotEmpty($dashboard['due_soon']);

        // Guru melihat antrean penilaian.
        $this->actingAsTeacher($world['teacher']);

        $teacherDashboard = $this->getJson('/api/v1/lms/dashboard')->assertOk()->json('data');

        $this->assertArrayHasKey('pending_grading', $teacherDashboard);
    }
}
