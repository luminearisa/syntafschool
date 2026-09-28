<?php

namespace App\Modules\LMS\Database\Seeders;

use App\Models\User;
use App\Modules\LMS\Domain\Contracts\UserProviderInterface;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseSection;
use App\Modules\LMS\Models\LmsEnrollment;
use Illuminate\Database\Seeder;

/**
 * Data contoh aktivitas Phase 2 agar UI dapat dicoba langsung:
 * satu course dengan tugas, kuis (bank soal), forum, lesson, polling, dan survei.
 */
class LmsActivityDemoSeeder extends Seeder
{
    public function run(): void
    {
        $teacher = User::query()->where('email', 'guru@syntaf.sch.id')->first()
            ?? User::query()->orderBy('id')->first();

        if ($teacher === null) {
            return;
        }

        $teacher->syncRoles(['guru']);

        $actor = app(UserProviderInterface::class)->find($teacher->id);

        if ($actor === null) {
            $this->command?->warn('Aktor LMS tidak dapat dibentuk; seeder aktivitas dilewati.');

            return;
        }

        $course = LmsCourse::query()->where('slug', 'literasi-digital-pelajar')->first()
            ?? LmsCourse::query()->first();

        if ($course === null) {
            $this->command?->warn('Course contoh belum ada; jalankan LmsDemoSeeder lebih dahulu.');

            return;
        }

        $section = $course->sections()->first()
            ?? $course->sections()->create(['title' => 'Aktivitas Praktik', 'sort_order' => 9, 'visibility' => 'VISIBLE']);

        if (LmsActivity::query()->where('course_id', $course->id)->exists()) {
            $this->command?->info('Aktivitas contoh sudah ada; seeder dilewati.');

            return;
        }

        $activityService = app(\App\Modules\LMS\Application\Services\ActivityService::class);
        $quizService = app(\App\Modules\LMS\Application\Services\QuizService::class);
        $questionService = app(\App\Modules\LMS\Application\Services\QuestionService::class);

        // ----------------------------------------------------------------- tugas
        $assignment = $activityService->create($section, [
            'activity_type' => 'ASSIGNMENT',
            'title' => 'Esai Literasi Digital',
            'description' => 'Tulis esai singkat tentang kebiasaan aman berinternet.',
            'instructions' => '<p>Minimal 200 kata. Unggah berkas PDF bila ada.</p>',
            'status' => 'PUBLISHED',
            'due_at' => now()->addDays(4)->toDateTimeString(),
            'close_at' => now()->addDays(6)->toDateTimeString(),
            'submission_type' => 'ONLINE_TEXT_AND_FILE',
            'require_text' => true,
            'maximum_grade' => 100,
            'passing_grade' => 70,
            'late_penalty_percent' => 10,
            'allowed_file_types' => ['PDF', 'DOCX'],
        ], $actor);

        // ----------------------------------------------------------------- kuis
        $bank = app(\App\Modules\LMS\Application\Services\QuestionBankService::class)
            ->create(['name' => 'Bank Literasi Digital', 'course_id' => $course->id], $actor);

        $category = app(\App\Modules\LMS\Application\Services\QuestionBankService::class)
            ->createCategory($bank, ['name' => 'Keamanan Daring']);

        $single = $questionService->create([
            'bank_id' => $bank->id,
            'category_id' => $category->id,
            'course_id' => $course->id,
            'name' => 'Kata sandi kuat',
            'question_type' => 'MULTIPLE_CHOICE_SINGLE',
            'question_text' => 'Manakah kata sandi yang paling kuat?',
            'default_mark' => 2,
            'options' => [
                ['option_key' => 'A', 'text' => '123456', 'is_correct' => false],
                ['option_key' => 'B', 'text' => 'Syntaf#2026!aman', 'is_correct' => true],
                ['option_key' => 'C', 'text' => 'namaku', 'is_correct' => false],
            ],
        ], $actor);

        $short = $questionService->create([
            'bank_id' => $bank->id,
            'category_id' => $category->id,
            'course_id' => $course->id,
            'name' => 'Istilah phishing',
            'question_type' => 'SHORT_ANSWER',
            'question_text' => 'Penipuan lewat tautan palsu disebut ____',
            'default_mark' => 1,
            'settings' => ['accepted_answers' => ['phishing'], 'case_sensitive' => false],
        ], $actor);

        $essay = $questionService->create([
            'bank_id' => $bank->id,
            'category_id' => $category->id,
            'course_id' => $course->id,
            'name' => 'Langkah melindungi akun',
            'question_type' => 'ESSAY',
            'question_text' => 'Sebutkan tiga langkah melindungi akun media sosialmu.',
            'default_mark' => 3,
        ], $actor);

        $quiz = $activityService->create($section, [
            'activity_type' => 'QUIZ',
            'title' => 'Kuis Keamanan Digital',
            'description' => 'Uji pemahamanmu tentang keamanan berinternet.',
            'status' => 'DRAFT',
            'time_limit_minutes' => 15,
            'attempts_allowed' => 2,
            'grade_strategy' => 'HIGHEST',
            'passing_grade' => 70,
            'shuffle_answers' => true,
            'review_options' => ['show_score' => true, 'show_correct_answer' => true, 'show_feedback' => true],
        ], $actor);

        $quizService->addQuestions($quizService->ensureConfig($quiz), [
            ['question_id' => $single->id, 'marks' => 2],
            ['question_id' => $short->id, 'marks' => 1],
            ['question_id' => $essay->id, 'marks' => 3],
        ]);

        $activityService->publish($quiz->fresh(), $actor);

        // ----------------------------------------------------------------- forum
        $forum = $activityService->create($section, [
            'activity_type' => 'FORUM',
            'title' => 'Forum Tanya Jawab Literasi',
            'description' => 'Tanyakan apa pun tentang literasi digital.',
            'status' => 'PUBLISHED',
            'forum_type' => 'Q_AND_A',
            'required_posts' => 2,
        ], $actor);

        // ----------------------------------------------------------------- lesson
        $lesson = $activityService->create($section, [
            'activity_type' => 'LESSON',
            'title' => 'Pelajaran: Jejak Digital',
            'status' => 'DRAFT',
        ], $actor);

        $lessonService = app(\App\Modules\LMS\Application\Services\LessonService::class);
        $config = $lessonService->ensureConfig($lesson);

        $pageOne = $lessonService->createPage($config, [
            'title' => 'Apa itu jejak digital?',
            'content' => '<p>Jejak digital adalah semua data yang kita tinggalkan saat memakai internet.</p>',
        ]);

        $pageTwo = $lessonService->createPage($config, [
            'title' => 'Menjaga jejak digital',
            'content' => '<p>Periksa pengaturan privasi dan hindari membagikan data pribadi.</p>',
        ]);

        $questionPage = $lessonService->createPage($config, [
            'title' => 'Cek pemahaman',
            'content' => '<p>Apakah mengunggah KTP ke media sosial itu aman?</p>',
            'page_type' => 'QUESTION',
        ]);

        $lessonService->updatePage($questionPage, [
            'branches' => [
                ['label' => 'Aman', 'answer_key' => 'aman', 'is_correct' => false, 'next_page_id' => $pageOne->id],
                ['label' => 'Tidak aman', 'answer_key' => 'tidak', 'is_correct' => true, 'next_page_id' => $pageTwo->id],
            ],
        ]);

        $activityService->publish($lesson->fresh(), $actor);

        // ----------------------------------------------------------------- polling & survei
        $activityService->create($section, [
            'activity_type' => 'CHOICE',
            'title' => 'Polling: media belajar favorit',
            'status' => 'PUBLISHED',
            'allow_multiple' => false,
            'limit_responses' => 1,
            'results_visibility' => 'AFTER_RESPONSE',
            'options' => [['text' => 'Video'], ['text' => 'Artikel'], ['text' => 'Diskusi'], ['text' => 'Praktik langsung']],
        ], $actor);

        $activityService->create($section, [
            'activity_type' => 'SURVEY',
            'title' => 'Survei umpan balik modul literasi',
            'status' => 'PUBLISHED',
            'anonymous' => true,
            'show_results_to_students' => true,
            'questions' => [
                ['question_type' => 'RATING', 'text' => 'Materi mudah dipahami?', 'rating_min' => 1, 'rating_max' => 5],
                ['question_type' => 'YES_NO', 'text' => 'Apakah tugasnya membantu?'],
                ['question_type' => 'TEXT', 'text' => 'Saran perbaikan', 'is_required' => false],
            ],
        ], $actor);

        // Pastikan peserta demo terdaftar pada course.
        foreach (User::query()->whereIn('email', ['siswa@syntaf.sch.id', 'siswa2@syntaf.sch.id'])->get() as $student) {
            LmsEnrollment::query()->firstOrCreate(
                ['course_id' => $course->id, 'user_id' => $student->id],
                [
                    'role' => 'STUDENT',
                    'status' => 'ACTIVE',
                    'enrollment_method' => 'MANUAL',
                    'enrolled_at' => now(),
                ],
            );
        }

        $this->command?->info('Aktivitas contoh Phase 2 dibuat pada course: '.$course->name);
    }
}
