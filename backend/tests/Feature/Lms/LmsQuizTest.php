<?php

namespace Tests\Feature\Lms;

use App\Modules\LMS\Models\LmsQuestion;
use App\Modules\LMS\Models\LmsQuizAttempt;
use App\Modules\LMS\Models\LmsQuizAttemptQuestion;
use Illuminate\Support\Facades\DB;

/**
 * Kuis: bank soal, tipe soal, versioning, pemilihan acak, timer server,
 * penilaian otomatis & manual, review, dan strategi nilai (§12–§31).
 */
class LmsQuizTest extends LmsTestCase
{
    /**
     * Menyiapkan course + aktivitas kuis + soal pilihan ganda.
     *
     * @return array{0: \App\Modules\LMS\Models\LmsActivity, 1: array<string, mixed>, 2: \App\Modules\LMS\Models\LmsQuestion}
     */
    protected function quizWorld(array $activityAttributes = [], array $questionAttributes = []): array
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();

        $activity = $this->makeActivity($section, array_merge([
            'activity_type' => 'QUIZ',
            'title' => 'Kuis Aljabar',
            'time_limit_minutes' => 30,
            'attempts_allowed' => 1,
            'maximum_grade' => 100,
            'passing_grade' => 70,
            'review_options' => ['show_score' => true, 'show_feedback' => true, 'show_correct_answer' => false],
        ], $activityAttributes));

        $question = $this->makeQuestion(array_merge([
            'course_id' => $course->id,
            'question_text' => '2 + 2 = ?',
            'default_mark' => 2,
            'options' => [
                ['option_key' => 'A', 'text' => '3', 'is_correct' => false],
                ['option_key' => 'B', 'text' => '4', 'is_correct' => true],
                ['option_key' => 'C', 'text' => '5', 'is_correct' => false],
            ],
        ], $questionAttributes), $course);

        app(\App\Modules\LMS\Application\Services\QuizService::class)
            ->addQuestions(
                app(\App\Modules\LMS\Application\Services\QuizService::class)->ensureConfig($activity),
                [['question_id' => $question->id, 'marks' => 2]],
            );

        $this->publishActivity($activity);

        return [$activity->fresh(), $world, $question->fresh('options')];
    }

    /** Soal pilihan ganda, benar/salah, isian, numerik, esai, menjodohkan (§15–§21). */
    public function test_semua_tipe_soal_dapat_dibuat(): void
    {
        $this->actingAsRole('guru');

        $multiple = $this->postJson('/api/v1/lms/questions', [
            'question_type' => 'MULTIPLE_CHOICE_MULTIPLE',
            'question_text' => 'Pilih bilangan prima',
            'options' => [
                ['option_key' => 'A', 'text' => '2', 'is_correct' => true],
                ['option_key' => 'B', 'text' => '3', 'is_correct' => true],
                ['option_key' => 'C', 'text' => '4', 'is_correct' => false],
            ],
        ])->assertCreated()->json('data');

        $this->assertSame(2, collect($multiple['options'])->where('is_correct', true)->count());

        $trueFalse = $this->postJson('/api/v1/lms/questions', [
            'question_type' => 'TRUE_FALSE',
            'question_text' => 'Bumi berbentuk bulat.',
        ])->assertCreated()->json('data');

        $this->assertCount(2, $trueFalse['options']);

        $this->postJson('/api/v1/lms/questions', [
            'question_type' => 'SHORT_ANSWER',
            'question_text' => 'Ibukota Indonesia adalah ____',
            'settings' => ['accepted_answers' => ['Jakarta', 'DKI Jakarta'], 'case_sensitive' => false],
        ])->assertCreated();

        $this->postJson('/api/v1/lms/questions', [
            'question_type' => 'NUMERICAL',
            'question_text' => 'Nilai pi?',
            'settings' => ['correct_answer' => 3.14, 'tolerance' => 0.01],
        ])->assertCreated();

        $this->postJson('/api/v1/lms/questions', [
            'question_type' => 'ESSAY',
            'question_text' => 'Jelaskan hukum Newton kedua.',
        ])->assertCreated();

        $matching = $this->postJson('/api/v1/lms/questions', [
            'question_type' => 'MATCHING',
            'question_text' => 'Jodohkan kota dan provinsi',
            'options' => [
                ['option_key' => 'L1', 'text' => 'Jakarta', 'match_target' => 'DKI Jakarta', 'is_correct' => false],
                ['option_key' => 'L2', 'text' => 'Bandung', 'match_target' => 'Jawa Barat', 'is_correct' => false],
            ],
        ])->assertCreated()->json('data');

        $this->assertSame('DKI Jakarta', $matching['options'][0]['match_target']);

        // Soal tanpa kunci pada pilihan ganda tunggal ditolak.
        $this->postJson('/api/v1/lms/questions', [
            'question_type' => 'MULTIPLE_CHOICE_SINGLE',
            'question_text' => 'Tanpa kunci',
            'options' => [
                ['option_key' => 'A', 'text' => 'Satu', 'is_correct' => false],
                ['option_key' => 'B', 'text' => 'Dua', 'is_correct' => false],
            ],
        ])->assertStatus(422);

        // Tipe lanjutan (CLOZE) belum tersedia di Phase 2.
        $this->postJson('/api/v1/lms/questions', [
            'question_type' => 'CLOZE',
            'question_text' => 'Isian rumpang',
        ])->assertStatus(422);
    }

    /** Versioning: soal yang diubah menaikkan versi, attempt lama tetap sah (§22). */
    public function test_versioning_soal_menjaga_hasil_percobaan_lama(): void
    {
        [$activity, $world, $question] = $this->quizWorld();
        $student = $world['students'][0];
        $studentUser = $world['studentUsers'][0];

        $this->enroll($activity->course, $studentUser);
        $this->actingAsStudent($student, $studentUser);

        $attempt = $this->postJson("/api/v1/lms/activities/{$activity->id}/quiz/attempts")
            ->assertCreated()->json('data');

        $attemptId = $attempt['attempt']['id'];
        $snapshotBefore = LmsQuizAttemptQuestion::query()->where('attempt_id', $attemptId)->firstOrFail()->snapshot;

        $this->assertSame('2 + 2 = ?', $snapshotBefore['question_text']);

        // Guru memperbaiki soal setelah attempt terjadi.
        $this->actingAsTeacher($world['teacher']);

        $this->putJson("/api/v1/lms/questions/{$question->id}", [
            'question_text' => '2 + 2 = ? (revisi)',
            'options' => [
                ['option_key' => 'A', 'text' => '3', 'is_correct' => false],
                ['option_key' => 'B', 'text' => '4', 'is_correct' => true],
            ],
        ])->assertOk()->assertJsonPath('data.version', 2);

        // Snapshot attempt tidak berubah; versi lama tersimpan.
        $this->assertSame('2 + 2 = ?', LmsQuizAttemptQuestion::query()
            ->where('attempt_id', $attemptId)->firstOrFail()->snapshot['question_text']);

        $this->assertDatabaseHas('lms_question_versions', ['question_id' => $question->id, 'version' => 1]);
        $this->assertDatabaseHas('lms_question_versions', ['question_id' => $question->id, 'version' => 2]);
    }

    /** Pemilihan acak mengambil soal dari kategori dan dipin per percobaan (§23). */
    public function test_pemilihan_soal_acak_dipin_per_percobaan(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);
        $section = $course->sections()->first();

        $bank = app(\App\Modules\LMS\Application\Services\QuestionBankService::class)
            ->create(['name' => 'Bank Aljabar', 'course_id' => $course->id], $this->actorFor($world['studentUsers'][0]));

        $category = app(\App\Modules\LMS\Application\Services\QuestionBankService::class)
            ->createCategory($bank, ['name' => 'Persamaan']);

        for ($i = 1; $i <= 10; $i++) {
            $this->makeQuestion([
                'bank_id' => $bank->id,
                'category_id' => $category->id,
                'course_id' => $course->id,
                'question_text' => 'Soal acak '.$i,
                'name' => 'Soal acak '.$i,
            ], $course);
        }

        $activity = $this->makeActivity($section, [
            'activity_type' => 'QUIZ',
            'title' => 'Kuis Acak',
            'attempts_allowed' => 1,
            'shuffle_questions' => true,
        ]);

        app(\App\Modules\LMS\Application\Services\QuizService::class)->addQuestions(
            app(\App\Modules\LMS\Application\Services\QuizService::class)->ensureConfig($activity),
            [['category_id' => $category->id, 'question_count' => 3, 'marks' => 1]],
        );

        $this->publishActivity($activity);

        foreach ([0, 1] as $index) {
            $this->enroll($course, $world['studentUsers'][$index]);
            $this->actingAsStudent($world['students'][$index], $world['studentUsers'][$index]);

            $attempt = $this->postJson("/api/v1/lms/activities/{$activity->id}/quiz/attempts")
                ->assertCreated()->json('data');

            $this->assertCount(3, $attempt['questions']);

            $pinned = LmsQuizAttemptQuestion::query()->where('attempt_id', $attempt['attempt']['id'])->pluck('question_id');
            $this->assertCount(3, $pinned->unique());

            $this->assertSame(3, \App\Modules\LMS\Models\LmsQuestion::query()
                ->whereIn('id', $pinned->all())
                ->where('category_id', $category->id)
                ->count());
        }
    }

    /** Penilaian otomatis menormalkan skor ke maximum_grade kuis (§24, §25). */
    public function test_penilaian_otomatis_dan_normalisasi_nilai(): void
    {
        [$activity, $world] = $this->quizWorld([
            'maximum_grade' => 100,
        ]);

        $student = $world['students'][0];
        $studentUser = $world['studentUsers'][0];
        $this->enroll($activity->course, $studentUser);
        $this->actingAsStudent($student, $studentUser);

        $attempt = $this->postJson("/api/v1/lms/activities/{$activity->id}/quiz/attempts")
            ->assertCreated()->json('data');

        $attemptId = $attempt['attempt']['id'];
        $questionId = $attempt['questions'][0]['question_id'];
        $correctOption = collect($attempt['questions'][0]['options'])->firstWhere('text', '4');

        $this->postJson("/api/v1/lms/attempts/{$attemptId}/answers", [
            'question_id' => $questionId,
            'answer_data' => ['option_id' => $correctOption['id']],
        ])->assertOk();

        $result = $this->postJson("/api/v1/lms/attempts/{$attemptId}/submit")
            ->assertOk()->json('data');

        $this->assertSame('GRADED', $result['attempt']['status']);
        $this->assertSame(2.0, (float) $result['attempt']['raw_score']);
        $this->assertSame(2.0, (float) $result['attempt']['maximum_score']);
        $this->assertSame(100.0, (float) $result['attempt']['final_grade']);
        $this->assertTrue($result['attempt']['passed']);

        $this->assertDatabaseHas('lms_activity_grades', [
            'activity_id' => $activity->id,
            'user_id' => $studentUser->id,
            'grade' => 100,
        ]);
    }

    /** Jawaban salah tetap terkirim dengan nilai 0 dan tidak lulus. */
    public function test_jawaban_salah_memberi_nilai_nol(): void
    {
        [$activity, $world] = $this->quizWorld();

        $studentUser = $world['studentUsers'][0];
        $this->enroll($activity->course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $attempt = $this->postJson("/api/v1/lms/activities/{$activity->id}/quiz/attempts")
            ->assertCreated()->json('data');

        $attemptId = $attempt['attempt']['id'];
        $wrongOption = collect($attempt['questions'][0]['options'])->firstWhere('text', '3');

        $this->postJson("/api/v1/lms/attempts/{$attemptId}/answers", [
            'question_id' => $attempt['questions'][0]['question_id'],
            'answer_data' => ['option_id' => $wrongOption['id']],
        ])->assertOk();

        $result = $this->postJson("/api/v1/lms/attempts/{$attemptId}/submit")->assertOk()->json('data');

        $this->assertSame(0.0, (float) $result['attempt']['final_grade']);
        $this->assertFalse($result['attempt']['passed']);
    }

    /** Esai tidak dinilai otomatis: menunggu guru lalu difinalkan (§20, §25). */
    public function test_esai_menunggu_penilaian_guru(): void
    {
        [$activity, $world] = $this->quizWorld(['passing_grade' => 60]);

        $essay = $this->makeQuestion([
            'course_id' => $activity->course_id,
            'question_type' => 'ESSAY',
            'question_text' => 'Jelaskan langkah pengerjaanmu.',
            'default_mark' => 3,
        ], $activity->course);

        app(\App\Modules\LMS\Application\Services\QuizService::class)->addQuestions(
            app(\App\Modules\LMS\Application\Services\QuizService::class)->ensureConfig($activity),
            [['question_id' => $essay->id, 'marks' => 3]],
        );

        $studentUser = $world['studentUsers'][0];
        $this->enroll($activity->course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $attempt = $this->postJson("/api/v1/lms/activities/{$activity->id}/quiz/attempts")
            ->assertCreated()->json('data');

        $attemptId = $attempt['attempt']['id'];
        $essayQuestionId = $essay->id;

        $this->postJson("/api/v1/lms/attempts/{$attemptId}/answers", [
            'question_id' => $essayQuestionId,
            'answer_data' => ['text' => 'Pertama saya menyamakan penyebut.'],
        ])->assertOk();

        $submitted = $this->postJson("/api/v1/lms/attempts/{$attemptId}/submit")->assertOk()->json('data');

        $this->assertTrue($submitted['attempt']['needs_manual_grading']);
        $this->assertSame('MANUAL_GRADING', $submitted['attempt']['status']);
        $this->assertNull($submitted['attempt']['final_grade']);

        // Guru menilai jawaban esai → attempt final.
        $this->actingAsTeacher($world['teacher']);

        $answerId = DB::table('lms_quiz_answers')
            ->where('attempt_id', $attemptId)
            ->where('question_id', $essayQuestionId)
            ->value('id');

        $this->postJson("/api/v1/lms/quiz-answers/{$answerId}/grade", [
            'marks' => 3,
            'feedback' => 'Penjelasan jelas.',
        ])->assertOk();

        $attemptModel = LmsQuizAttempt::query()->findOrFail($attemptId);

        $this->assertSame('GRADED', $attemptModel->status?->value);
        $this->assertFalse((bool) $attemptModel->needs_manual_grading);
        $this->assertNotNull($attemptModel->final_grade);
    }

    /** Timer dihitung server; melewati batas → submit otomatis (§27). */
    public function test_timer_server_side_mengirim_otomatis(): void
    {
        [$activity, $world] = $this->quizWorld(['time_limit_minutes' => 1]);

        $studentUser = $world['studentUsers'][0];
        $this->enroll($activity->course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $attempt = $this->postJson("/api/v1/lms/activities/{$activity->id}/quiz/attempts")
            ->assertCreated()->json('data');

        $attemptId = $attempt['attempt']['id'];

        $this->assertNotNull($attempt['attempt']['expires_at']);
        $this->assertNotNull($attempt['attempt']['server_time']);

        // Timer dihitung server (selisih < 1 detik dari 60 menit pertama).
        $this->assertGreaterThan(55, (int) $attempt['attempt']['seconds_remaining']);
        $this->assertLessThanOrEqual(60, (int) $attempt['attempt']['seconds_remaining']);

        // Waktu dipaksa habis di server.
        LmsQuizAttempt::query()->whereKey($attemptId)->update(['expires_at' => now()->subMinute()]);

        $this->getJson("/api/v1/lms/attempts/{$attemptId}")
            ->assertOk()
            ->assertJsonPath('data.attempt.status', 'EXPIRED');

        // Menyimpan jawaban setelah waktu habis ditolak.
        $this->postJson("/api/v1/lms/attempts/{$attemptId}/answers", [
            'question_id' => $attempt['questions'][0]['question_id'],
            'answer_data' => ['option_id' => 1],
        ])->assertStatus(422);
    }

    /** Kunci jawaban tidak pernah dikirim ke siswa sebelum selesai (§28). */
    public function test_kunci_jawaban_tidak_bocor_saat_percobaan_berjalan(): void
    {
        [$activity, $world] = $this->quizWorld();

        $studentUser = $world['studentUsers'][0];
        $this->enroll($activity->course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $attempt = $this->postJson("/api/v1/lms/activities/{$activity->id}/quiz/attempts")
            ->assertCreated()->json('data');

        foreach ($attempt['questions'] as $question) {
            $this->assertNull($question['correct_option_ids']);
            foreach ($question['options'] as $option) {
                $this->assertNull($option['is_correct'], 'Kunci jawaban tidak boleh dikirim ke browser.');
            }
        }

        // Review kuis pun belum terbuka karena attempt belum selesai.
        $this->assertSame([], $attempt['review']);
        $this->assertNull($attempt['attempt']['final_grade']);
    }

    /** Review mengikuti konfigurasi kuis (§29). */
    public function test_review_mengikuti_konfigurasi(): void
    {
        [$activity, $world] = $this->quizWorld([
            'review_options' => ['show_score' => true, 'show_correct_answer' => true, 'show_feedback' => true],
        ]);

        $studentUser = $world['studentUsers'][0];
        $this->enroll($activity->course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $attempt = $this->postJson("/api/v1/lms/activities/{$activity->id}/quiz/attempts")
            ->assertCreated()->json('data');

        $attemptId = $attempt['attempt']['id'];
        $correctOption = collect($attempt['questions'][0]['options'])->firstWhere('text', '4');

        $this->postJson("/api/v1/lms/attempts/{$attemptId}/answers", [
            'question_id' => $attempt['questions'][0]['question_id'],
            'answer_data' => ['option_id' => $correctOption['id']],
        ])->assertOk();

        $this->postJson("/api/v1/lms/attempts/{$attemptId}/submit")->assertOk();

        $result = $this->getJson("/api/v1/lms/attempts/{$attemptId}/result")->assertOk()->json('data');

        $this->assertSame(100.0, (float) $result['attempt']['final_grade']);
        $this->assertNotNull($result['questions'][0]['correct_option_ids']);
        $this->assertTrue($result['questions'][0]['answer']['is_correct']);
    }

    /** Strategi nilai tertinggi dari beberapa percobaan (§30). */
    public function test_strategi_nilai_tertinggi_dari_beberapa_percobaan(): void
    {
        [$activity, $world] = $this->quizWorld([
            'attempts_allowed' => 2,
            'grade_strategy' => 'HIGHEST',
        ]);

        $studentUser = $world['studentUsers'][0];
        $this->enroll($activity->course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        // Percobaan pertama: jawaban salah.
        $first = $this->postJson("/api/v1/lms/activities/{$activity->id}/quiz/attempts")
            ->assertCreated()->json('data');
        $wrong = collect($first['questions'][0]['options'])->firstWhere('text', '3');
        $this->postJson("/api/v1/lms/attempts/{$first['attempt']['id']}/answers", [
            'question_id' => $first['questions'][0]['question_id'],
            'answer_data' => ['option_id' => $wrong['id']],
        ])->assertOk();
        $this->postJson("/api/v1/lms/attempts/{$first['attempt']['id']}/submit")->assertOk();

        // Percobaan kedua: jawaban benar.
        $second = $this->postJson("/api/v1/lms/activities/{$activity->id}/quiz/attempts")
            ->assertCreated()->json('data');
        $correct = collect($second['questions'][0]['options'])->firstWhere('text', '4');
        $this->postJson("/api/v1/lms/attempts/{$second['attempt']['id']}/answers", [
            'question_id' => $second['questions'][0]['question_id'],
            'answer_data' => ['option_id' => $correct['id']],
        ])->assertOk();
        $this->postJson("/api/v1/lms/attempts/{$second['attempt']['id']}/submit")->assertOk();

        // Jatah percobaan habis → tidak boleh memulai lagi.
        $this->postJson("/api/v1/lms/activities/{$activity->id}/quiz/attempts")->assertStatus(422);

        $view = $this->getJson("/api/v1/lms/activities/{$activity->id}/quiz")->assertOk()->json('data');

        $this->assertSame(2, $view['attempts_used']);
        $this->assertSame(0, $view['attempts_remaining']);
        $this->assertSame(100.0, (float) $view['final_grade'], 'Strategi HIGHEST harus memakai nilai percobaan terbaik.');
    }

    /** Percobaan yang sedang berjalan dilanjutkan, bukan dibuat ulang (§25). */
    public function test_memulai_ulang_mengembalikan_percobaan_berjalan(): void
    {
        [$activity, $world] = $this->quizWorld();

        $studentUser = $world['studentUsers'][0];
        $this->enroll($activity->course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $first = $this->postJson("/api/v1/lms/activities/{$activity->id}/quiz/attempts")->assertCreated()->json('data');
        $second = $this->postJson("/api/v1/lms/activities/{$activity->id}/quiz/attempts")->assertCreated()->json('data');

        $this->assertSame($first['attempt']['id'], $second['attempt']['id']);
        $this->assertSame(1, LmsQuizAttempt::query()->count());
    }

    /** Navigasi SEQUENTIAL menolak melompati soal yang belum dijawab (§13). */
    public function test_navigasi_berurutan_menolak_lompatan(): void
    {
        [$activity, $world, $question] = $this->quizWorld(['navigation_method' => 'SEQUENTIAL']);

        $second = $this->makeQuestion([
            'course_id' => $activity->course_id,
            'question_text' => '3 + 3 = ?',
            'options' => [
                ['option_key' => 'A', 'text' => '6', 'is_correct' => true],
                ['option_key' => 'B', 'text' => '7', 'is_correct' => false],
            ],
        ], $activity->course);

        app(\App\Modules\LMS\Application\Services\QuizService::class)->addQuestions(
            app(\App\Modules\LMS\Application\Services\QuizService::class)->ensureConfig($activity),
            [['question_id' => $second->id, 'marks' => 1]],
        );

        $studentUser = $world['studentUsers'][0];
        $this->enroll($activity->course, $studentUser);
        $this->actingAsStudent($world['students'][0], $studentUser);

        $attempt = $this->postJson("/api/v1/lms/activities/{$activity->id}/quiz/attempts")
            ->assertCreated()->json('data');

        // Soal kedua tidak boleh dijawab sebelum soal pertama.
        $this->postJson("/api/v1/lms/attempts/{$attempt['attempt']['id']}/answers", [
            'question_id' => $attempt['questions'][1]['question_id'],
            'answer_data' => ['option_id' => $attempt['questions'][1]['options'][0]['id']],
        ])->assertStatus(422);

        // Soal pertama → jawab → soal kedua boleh.
        $this->postJson("/api/v1/lms/attempts/{$attempt['attempt']['id']}/answers", [
            'question_id' => $attempt['questions'][0]['question_id'],
            'answer_data' => ['option_id' => $attempt['questions'][0]['options'][0]['id']],
        ])->assertOk();

        $this->postJson("/api/v1/lms/attempts/{$attempt['attempt']['id']}/answers", [
            'question_id' => $attempt['questions'][1]['question_id'],
            'answer_data' => ['option_id' => $attempt['questions'][1]['options'][0]['id']],
        ])->assertOk();
    }

    /** Statistik kuis: rata-rata, lulus, dan per soal (§31). */
    public function test_statistik_kuis_dan_per_soal(): void
    {
        [$activity, $world] = $this->quizWorld();

        foreach ([0, 1] as $index) {
            $user = $world['studentUsers'][$index];
            $this->enroll($activity->course, $user);
            $this->actingAsStudent($world['students'][$index], $user);

            $attempt = $this->postJson("/api/v1/lms/activities/{$activity->id}/quiz/attempts")
                ->assertCreated()->json('data');

            $option = $index === 0
                ? collect($attempt['questions'][0]['options'])->firstWhere('text', '4')
                : collect($attempt['questions'][0]['options'])->firstWhere('text', '5');

            $this->postJson("/api/v1/lms/attempts/{$attempt['attempt']['id']}/answers", [
                'question_id' => $attempt['questions'][0]['question_id'],
                'answer_data' => ['option_id' => $option['id']],
            ])->assertOk();

            $this->postJson("/api/v1/lms/attempts/{$attempt['attempt']['id']}/submit")->assertOk();
        }

        $this->actingAsTeacher($world['teacher']);

        $statistics = $this->getJson("/api/v1/lms/activities/{$activity->id}/quiz/statistics")
            ->assertOk()->json('data');

        $this->assertSame(2, $statistics['attempts']);
        $this->assertSame(2, $statistics['students_attempted']);
        $this->assertSame(50.0, (float) $statistics['average']);
        $this->assertSame(50.0, (float) $statistics['pass_rate']);
        $this->assertSame(1, $statistics['questions'][0]['correct']);
        $this->assertSame(1, $statistics['questions'][0]['incorrect']);
        $this->assertSame(50.0, (float) $statistics['questions'][0]['correct_percent']);
    }

    /** Bank soal: kategori bertingkat & pemakaian lintas course (§14). */
    public function test_bank_soal_dengan_kategori_bertingkat(): void
    {
        $world = $this->world();
        $course = $this->publishCourse($this->makeCourse($world), $world);

        $user = $this->actingAsTeacher($world['teacher']);

        $bank = $this->postJson('/api/v1/lms/question-banks', [
            'name' => 'Bank Matematika',
            'course_id' => $course->id,
        ])->assertCreated()->json('data');

        $root = $this->postJson("/api/v1/lms/question-banks/{$bank['id']}/categories", ['name' => 'Aljabar'])
            ->assertCreated()->json('data.categories.0');

        $this->postJson("/api/v1/lms/question-banks/{$bank['id']}/categories", [
            'name' => 'Persamaan Linear',
            'parent_id' => $root['id'],
        ])->assertCreated();

        $tree = $this->getJson("/api/v1/lms/question-banks/{$bank['id']}")->assertOk()->json('data');

        $this->assertCount(1, $tree['categories']);
        $this->assertCount(1, $tree['categories'][0]['children']);

        // Kategori yang masih berisi soal tidak dapat dihapus.
        $this->makeQuestion(['bank_id' => $bank['id'], 'category_id' => $root['id']], $course);

        $this->deleteJson("/api/v1/lms/question-categories/{$root['id']}")->assertStatus(422);
    }
}
