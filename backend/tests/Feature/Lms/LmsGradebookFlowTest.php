<?php

namespace Tests\Feature\Lms;

use App\Models\Assessment;
use App\Modules\LMS\Application\Services\AssignmentGradingService;
use App\Modules\LMS\Application\Services\AssignmentSubmissionService;
use App\Modules\LMS\Application\Services\FinalGradeService;
use App\Modules\LMS\Application\Services\GradeReleaseService;
use App\Modules\LMS\Application\Services\GradeSyncService;
use App\Modules\LMS\Models\LmsAssignment;
use App\Modules\LMS\Models\LmsGradeItem;
use Database\Seeders\AssessmentTypeSeeder;

/**
 * Alur menyeluruh Phase 3 (§61): tugas→nilai, kuis→nilai, rubric, outcome,
 * kompetensi, sampai rilis & sinkronisasi akademik.
 */
class LmsGradebookFlowTest extends LmsTestCase
{
    public function test_alur_tugas_sampai_nilai_dirilis_dan_dilihat_siswa_dan_wali(): void
    {
        $world = $this->world(1);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->firstOrFail();
        $scenario = $this->gradebookScenario($world, $course);
        $student = $scenario['user_ids'][0];

        // 1) Guru membuat & menerbitkan tugas.
        $activity = $this->publishActivity($this->makeActivity($section, [
            'title' => 'Esai Literasi Digital',
            'activity_type' => 'ASSIGNMENT',
        ]));

        $assignment = LmsAssignment::query()->where('activity_id', $activity->id)->firstOrFail();
        $item = LmsGradeItem::query()->where('activity_id', $activity->id)->firstOrFail();

        $this->assertNotNull($assignment->id);

        // 2) Siswa mengirim jawaban.
        $studentUser = $world['studentUsers'][0];
        $enrollment = $course->enrollmentFor($student);
        $submission = app(AssignmentSubmissionService::class)->submit(
            $activity,
            $enrollment,
            $student,
            ['text_content' => 'Aman berinternet berarti menjaga data pribadi dan berpikir kritis.'],
            $this->actorFor($studentUser),
        );

        $this->assertNotNull($submission->submitted_at);

        // 3) Guru menilai → otomatis menjadi assessment result (observer §13).
        $teacher = $this->actingAsTeacher($world['teacher']);
        $actor = $this->actorFor($teacher);

        app(AssignmentGradingService::class)->grade($submission, [
            'grade' => 92,
            'feedback' => 'Struktur baik, tambahkan contoh nyata.',
        ], $actor);

        $result = $item->resultFor($student);

        $this->assertNotNull($result, 'Nilai tugas harus otomatis masuk gradebook.');
        $this->assertEqualsWithDelta(92.0, (float) $result?->final_score, 0.01);
        $this->assertNotNull($result?->activity_grade_id, 'Hasil gradebook harus menautkan jejak nilai aktivitas (§13).');
        $this->assertSame('ASSIGNMENT', $result?->source->value);

        // 4) Nilai akhir + rilis.
        $final = app(FinalGradeService::class)->forStudent($scenario['gradebook']->refresh(), $student);
        $this->assertEqualsWithDelta(92.0, (float) $final['normalized'], 0.01);

        app(GradeReleaseService::class)->releaseStudent($scenario['gradebook'], $student, $actor);

        // 5) Siswa melihat nilai & umpan baliknya.
        $this->actingAsStudent($world['students'][0], $studentUser);
        $grades = $this->getJson('/api/v1/lms/my/grades')->assertOk();

        $this->assertTrue($grades->json('data.courses.0.is_released'));
        $this->assertEqualsWithDelta(92.0, (float) $grades->json('data.courses.0.current_grade'), 0.01);
        $this->assertSame('Struktur baik, tambahkan contoh nyata.', $grades->json('data.courses.0.feedback.0.feedback'));

        // 6) Wali melihat nilai anak yang sah (read-only).
        $guardian = \App\Models\Guardian::create(['full_name' => 'Wali Adi', 'phone' => '0812000001', 'is_active' => true]);
        \App\Models\StudentGuardian::create([
            'student_id' => $world['students'][0]->id,
            'guardian_id' => $guardian->id,
            'relation' => 'ayah',
            'is_primary' => true,
        ]);

        $this->actingAsParent($guardian);

        $child = $this->getJson('/api/v1/lms/children/'.$student.'/grades')->assertOk();

        $this->assertEqualsWithDelta(92.0, (float) $child->json('data.courses.0.current_grade'), 0.01);
        $this->assertSame('A', $child->json('data.courses.0.letter'));
        $this->assertNotEmpty($child->json('data.courses.0.recent_results'));
    }

    public function test_alur_kuis_penilaian_otomatis_masuk_gradebook(): void
    {
        $world = $this->world(1);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->firstOrFail();
        $scenario = $this->gradebookScenario($world, $course);
        $student = $scenario['user_ids'][0];

        // Soal disiapkan lebih dulu: kuis tanpa soal tidak boleh diterbitkan.
        $activity = $this->makeActivity($section, [
            'title' => 'Kuis Keamanan Digital',
            'activity_type' => 'QUIZ',
        ]);

        $quiz = app(\App\Modules\LMS\Application\Services\QuizService::class)->ensureConfig($activity);
        $question = $this->makeQuestion();

        app(\App\Modules\LMS\Application\Services\QuizService::class)->addQuestions($quiz, [
            ['question_id' => $question->id, 'marks' => 1],
        ]);
        app(\App\Modules\LMS\Application\Services\QuizService::class)->updateForActivity($activity, [
            'maximum_grade' => 100,
            'passing_grade' => 70,
            'maximum_attempts' => 2,
        ]);

        $activity = $this->publishActivity($activity);

        $studentUser = $world['studentUsers'][0];
        $attempt = app(\App\Modules\LMS\Application\Services\QuizAttemptService::class)
            ->start($activity->refresh(), $course->enrollmentFor($student));

        $correct = $question->options()->where('is_correct', true)->firstOrFail();

        app(\App\Modules\LMS\Application\Services\QuizAttemptService::class)
            ->saveAnswer($attempt, (int) $question->id, ['answer_data' => ['option_id' => (int) $correct->id]]);

        app(\App\Modules\LMS\Application\Services\QuizAttemptService::class)
            ->submit($attempt->refresh(), $this->actorFor($studentUser));

        $item = LmsGradeItem::query()->where('activity_id', $activity->id)->firstOrFail();
        $result = $item->resultFor($student);

        $this->assertNotNull($result, 'Nilai kuis otomatis harus tercatat di gradebook.');
        $this->assertEqualsWithDelta(100.0, (float) $result?->normalized(), 0.01);
        $this->assertSame('QUIZ', $result?->source->value);

        $final = app(FinalGradeService::class)->forStudent($scenario['gradebook']->refresh(), $student);
        $this->assertEqualsWithDelta(100.0, (float) $final['normalized'], 0.01);
    }

    public function test_alur_rubric_sampai_hasil_akhir_gradebook(): void
    {
        $world = $this->world(1);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $scenario = $this->gradebookScenario($world, $course);
        $student = $scenario['user_ids'][0];

        $teacher = $this->actingAsTeacher($world['teacher']);
        $actor = $this->actorFor($teacher);

        $item = $this->makeManualItem($scenario['gradebook'], ['name' => 'Presentasi Proyek', 'maximum_grade' => 100, 'weight' => 100]);

        $rubric = app(\App\Modules\LMS\Application\Services\RubricService::class)->create([
            'course_id' => $course->id,
            'name' => 'Rubric Presentasi',
            'grading_method' => 'POINTS',
            'criteria' => [
                ['name' => 'Content', 'levels' => [['name' => 'Excellent', 'score' => 4], ['name' => 'Good', 'score' => 3]]],
                ['name' => 'Structure', 'levels' => [['name' => 'Excellent', 'score' => 4], ['name' => 'Good', 'score' => 3]]],
            ],
        ], $actor)->load('criteria.levels');

        $selections = [
            $rubric->criteria[0]->id => ['level_id' => (int) $rubric->criteria[0]->levels->firstWhere('name', 'Excellent')->id],
            $rubric->criteria[1]->id => ['level_id' => (int) $rubric->criteria[1]->levels->firstWhere('name', 'Good')->id],
        ];

        $result = app(\App\Modules\LMS\Application\Services\RubricAssessmentService::class)->assess(
            app(\App\Modules\LMS\Application\Services\RubricAssessmentService::class)
                ->start($rubric, $student, $item, null, $actor),
            $selections,
            $actor,
            true,
            'Presentasi meyakinkan.',
        );

        // 7/8 → 87.5 pada skala 100.
        $this->assertEqualsWithDelta(7.0, $result['total'], 0.01);
        $this->assertEqualsWithDelta(87.5, $result['normalized'], 0.01);

        $final = app(FinalGradeService::class)->forStudent($scenario['gradebook']->refresh(), $student);
        $this->assertEqualsWithDelta(87.5, (float) $final['normalized'], 0.01);
        $this->assertSame('B', $final['letter']);
    }

    public function test_outcome_dan_kompetensi_terisi_dari_aktivitas(): void
    {
        $world = $this->world(1);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->firstOrFail();
        $scenario = $this->gradebookScenario($world, $course);
        $student = $scenario['user_ids'][0];

        $activity = $this->publishActivity($this->makeActivity($section, ['title' => 'Proyek Data']));
        $item = LmsGradeItem::query()->where('activity_id', $activity->id)->firstOrFail();

        $teacher = $this->actingAsTeacher($world['teacher']);
        $actor = $this->actorFor($teacher);

        $outcome = app(\App\Modules\LMS\Application\Services\LearningOutcomeService::class)->create($course, [
            'statement' => 'Mengolah data sederhana.',
            'activity_ids' => [$activity->id],
        ], $actor);

        $framework = app(\App\Modules\LMS\Application\Services\CompetencyService::class)->createFramework(['name' => 'Data'], $actor);
        $competency = app(\App\Modules\LMS\Application\Services\CompetencyService::class)->createCompetency($framework, ['name' => 'Analisis Data'], $actor);
        app(\App\Modules\LMS\Application\Services\CompetencyService::class)->mapToCourse($course, [$competency->id], $actor);

        $this->recordGrade($item, $student, 88);

        $rows = app(\App\Modules\LMS\Application\Services\OutcomeAssessmentService::class)->recalculate($outcome, [$student], $actor);
        $this->assertSame('ACHIEVED', $rows[0]['status']);

        $record = app(\App\Modules\LMS\Application\Services\CompetencyAssessmentService::class)
            ->assess($competency, $student, \App\Modules\LMS\Domain\Enums\CompetencyStatus::COMPETENT, $actor, $course);

        $evidence = app(\App\Modules\LMS\Application\Services\CompetencyAssessmentService::class)
            ->recordActivityEvidence($record, $activity, $actor);

        $this->assertEqualsWithDelta(88.0, (float) $evidence->score, 0.01);
        $this->assertSame($item->id, (int) $evidence->grade_item_id);
    }

    public function test_alur_akademik_gabungan_rilis_kunci_sinkronisasi(): void
    {
        $this->seed(AssessmentTypeSeeder::class);
        config(['lms.standalone' => false, 'lms.integration.push_grades' => true]);

        $world = $this->world(1);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $scenario = $this->gradebookScenario($world, $course);
        $student = $scenario['user_ids'][0];

        $item = $this->makeManualItem($scenario['gradebook'], ['name' => 'Ujian Akhir']);
        $this->recordGrade($item, $student, 86);

        $teacherActor = $this->actorFor($this->actingAsTeacher($world['teacher']));
        app(GradeReleaseService::class)->releaseStudent($scenario['gradebook'], $student, $teacherActor);

        $admin = $this->actingAsRole('admin-sekolah');
        $actor = $this->actorFor($admin);

        $sync = app(GradeSyncService::class)->syncStudent($scenario['gradebook'], $student, $actor);

        $this->assertSame('SYNCED', $sync['status']);
        $this->assertSame(1, Assessment::query()->count());
        $this->assertEqualsWithDelta(86.0, (float) \App\Models\Score::query()->firstOrFail()->score, 0.01);

        // Koreksi setelah sinkronisasi harus melewati pembukaan kunci berizin.
        $result = $item->resultFor($student);
        $admin = $this->actingAsRole('admin-sekolah');
        $adminActor = $this->actorFor($admin);

        $release = \App\Modules\LMS\Models\LmsGradeRelease::query()->firstOrFail();
        app(GradeReleaseService::class)->unlock($release, $adminActor, 'Nilai esai perlu dikoreksi');

        $correction = app(\App\Modules\LMS\Application\Services\GradeCorrectionService::class)
            ->correct($result->fresh() ?? $result, 90.0, 'Salah hitung butir esai', $adminActor);

        $this->assertSame(86.0, $correction['previous']);
        $this->assertSame(90.0, $correction['corrected']);

        $second = app(GradeSyncService::class)->syncStudent($scenario['gradebook'], $student, $adminActor);

        $this->assertSame('SYNCED', $second['status']);
        $this->assertSame(1, Assessment::query()->count());
        $this->assertEqualsWithDelta(90.0, (float) \App\Models\Score::query()->firstOrFail()->refresh()->score, 0.01);
    }

    public function test_gradebook_guru_menampilkan_matriks_nilai_dan_distribusi(): void
    {
        $world = $this->world(3);
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $scenario = $this->gradebookScenario($world, $course);

        $first = $this->makeManualItem($scenario['gradebook'], ['name' => 'Tugas 1']);
        $second = $this->makeManualItem($scenario['gradebook'], ['name' => 'Kuis 1']);

        foreach ($scenario['user_ids'] as $index => $userId) {
            $this->recordGrade($first, $userId, [80.0, 75.0, 30.0][$index]);
            $this->recordGrade($second, $userId, [90.0, 80.0, 40.0][$index]);
        }

        $this->actingAsTeacher($world['teacher']);

        $sheet = $this->getJson('/api/v1/lms/courses/'.$course->id.'/gradebook/sheet')->assertOk();

        $this->assertCount(3, $sheet->json('data.rows'));
        $this->assertCount(2, $sheet->json('data.items'));
        $this->assertCount(2, $sheet->json('data.rows.0.results'));
        $this->assertEqualsWithDelta(85.0, (float) $sheet->json('data.rows.0.final.normalized'), 0.01);
        $this->assertEqualsWithDelta(65.83, (float) $sheet->json('data.statistics.average'), 0.01);

        $filtered = $this->getJson('/api/v1/lms/courses/'.$course->id.'/gradebook/sheet?below_passing=1')->assertOk();
        $this->assertCount(1, $filtered->json('data.rows'), 'Filter di bawah KKM hanya menyisakan siswa yang belum lulus.');
    }
}
