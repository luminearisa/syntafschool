<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\QuizAttemptStatus;
use App\Modules\LMS\Domain\Enums\QuizNavigation;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsQuestionVersion;
use App\Modules\LMS\Models\LmsQuiz;
use App\Modules\LMS\Models\LmsQuizAnswer;
use App\Modules\LMS\Models\LmsQuizAttempt;
use App\Modules\LMS\Models\LmsQuizAttemptQuestion;
use App\Modules\LMS\Models\LmsQuizQuestion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Percobaan kuis (§25–§29, §54).
 *
 * Prinsip yang dijaga:
 *  - **Server adalah sumber kebenaran waktu**: `expires_at` dihitung di server;
 *    timer JavaScript hanya untuk tampilan.
 *  - **Idempoten**: memulai ulang saat sudah ada percobaan berjalan akan
 *    mengembalikan percobaan itu, bukan membuat baru.
 *  - **Tidak membocorkan kunci**: payload soal siswa tidak pernah memuat
 *    `is_correct`/`correct_answer` selama percobaan berjalan.
 *  - Soal dipin per percobaan sehingga pemilihan acak & versioning tetap sah.
 */
class QuizAttemptService
{
    use LogsLmsAudit;

    public function __construct(
        private readonly QuizService $quizzes,
        private readonly QuizGradingService $grading,
        private readonly QuestionService $questions,
        private readonly ActivityCompletionService $completion,
    ) {}

    /**
     * Mulai (atau lanjutkan) percobaan kuis.
     *
     * @param  array<string, mixed>  $data
     */
    public function start(
        LmsActivity $activity,
        LmsEnrollment $enrollment,
        array $data = [],
        ?int $studentId = null,
    ): LmsQuizAttempt {
        $quiz = $this->quizzes->ensureConfig($activity);
        $userId = $enrollment->user_id;

        $existing = $this->currentAttempt($quiz, $userId);

        if ($existing !== null) {
            $this->assertNotExpired($existing);

            return $existing;
        }

        $this->assertOpen($quiz);
        $this->assertAttemptsRemaining($quiz, $userId);
        $this->assertPassword($quiz, $data['password'] ?? null);

        return DB::transaction(function () use ($activity, $quiz, $enrollment, $userId, $studentId): LmsQuizAttempt {
            $attemptNumber = (int) LmsQuizAttempt::query()
                ->where('quiz_id', $quiz->id)
                ->where('user_id', $userId)
                ->max('attempt_number') + 1;

            $limit = $quiz->time_limit_minutes === null ? null : (int) $quiz->time_limit_minutes;

            $attempt = LmsQuizAttempt::create([
                'quiz_id' => $quiz->id,
                'enrollment_id' => $enrollment->id,
                'user_id' => $userId,
                'student_id' => $studentId ?? $enrollment->student_id,
                'attempt_number' => $attemptNumber,
                'started_at' => now(),
                'expires_at' => $limit === null ? null : now()->addMinutes($limit),
                'time_limit_minutes' => $limit,
                'status' => QuizAttemptStatus::IN_PROGRESS->value,
            ]);

            $this->pinQuestions($attempt, $quiz);

            $this->audit('LMS_QUIZ_ATTEMPT_STARTED', $activity, [
                'attempt_id' => $attempt->id,
                'attempt_number' => $attemptNumber,
            ]);

            return $attempt->fresh(['questions']);
        });
    }

    /**
     * Pin daftar soal untuk percobaan ini (§22–§23).
     */
    public function pinQuestions(LmsQuizAttempt $attempt, LmsQuiz $quiz): void
    {
        $slots = $quiz->questions()->with('question.options')->get();

        $entries = [];
        $usedQuestionIds = [];

        foreach ($slots as $slot) {
            if ($slot->isRandomSlot()) {
                $category = $slot->category;
                $picked = $this->questions->randomFromCategory(
                    $category,
                    (int) $slot->question_count,
                    $usedQuestionIds,
                );

                foreach ($picked as $question) {
                    $question->loadMissing('options');
                    $entries[] = [
                        'question' => $question,
                        'marks' => (float) ($slot->marks ?: $question->default_mark),
                        'sort_order' => count($entries) + 1,
                    ];
                    $usedQuestionIds[] = (int) $question->id;
                }

                continue;
            }

            $question = $slot->question;

            if ($question === null) {
                continue;
            }

            $entries[] = [
                'question' => $question,
                'marks' => (float) $slot->marks,
                'sort_order' => count($entries) + 1,
            ];
            $usedQuestionIds[] = (int) $question->id;
        }

        if ($entries === []) {
            throw ValidationException::withMessages([
                'quiz' => 'Kuis belum memiliki soal. Hubungi guru Anda.',
            ]);
        }

        if ($quiz->shuffle_questions) {
            shuffle($entries);
        }

        $maximum = 0.0;

        foreach ($entries as $index => $entry) {
            /** @var \App\Modules\LMS\Models\LmsQuestion $question */
            $question = $entry['question'];
            $version = LmsQuestionVersion::query()
                ->where('question_id', $question->id)
                ->where('version', (int) $question->version)
                ->first();

            LmsQuizAttemptQuestion::create([
                'attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'question_version_id' => $version?->id,
                'marks' => $entry['marks'],
                'sort_order' => $index + 1,
                'snapshot' => $version?->toSnapshot() ?? $question->toSnapshot(),
            ]);

            $maximum += (float) $entry['marks'];
        }

        $attempt->forceFill(['maximum_score' => round($maximum, 2)])->save();
    }

    /**
     * Simpan jawaban satu soal (§26, §54).
     *
     * @param  array<string, mixed>  $payload
     */
    public function saveAnswer(LmsQuizAttempt $attempt, int $questionId, array $payload): LmsQuizAnswer
    {
        $this->assertOpenForWriting($attempt);

        $attemptQuestion = $attempt->questions()->where('question_id', $questionId)->first();

        if ($attemptQuestion === null) {
            throw ValidationException::withMessages([
                'question_id' => 'Soal ini bukan bagian dari percobaan Anda.',
            ]);
        }

        $this->assertNavigationAllows($attempt, $attemptQuestion);

        $answer = LmsQuizAnswer::updateOrCreate(
            ['attempt_id' => $attempt->id, 'question_id' => $questionId],
            [
                'question_version_id' => $attemptQuestion->question_version_id,
                'answer' => $this->textAnswer($payload),
                'answer_data' => $payload['answer_data'] ?? null,
            ],
        );

        return $answer->fresh();
    }

    /**
     * Kirim percobaan: dinilai otomatis, atau menunggu guru bila ada esai (§20, §25).
     */
    public function submit(LmsQuizAttempt $attempt, ?LmsActor $actor = null, bool $expired = false): LmsQuizAttempt
    {
        if (! $attempt->isInProgress()) {
            return $attempt->fresh(['answers', 'questions']);
        }

        return DB::transaction(function () use ($attempt, $actor, $expired): LmsQuizAttempt {
            $attempt->forceFill([
                'submitted_at' => now(),
                'status' => $expired ? QuizAttemptStatus::EXPIRED->value : QuizAttemptStatus::SUBMITTED->value,
                // Penanda agar status EXPIRED tetap dipertahankan setelah dinilai (§27).
                'metadata' => array_merge((array) $attempt->metadata, $expired ? ['expired' => true] : []),
            ])->save();

            $this->grading->autoGrade($attempt);

            // Attempt yang ditandai EXPIRED tetap dinilai, hanya statusnya berbeda.
            if ($expired) {
                $attempt->forceFill(['status' => QuizAttemptStatus::EXPIRED->value])->save();
            }

            $final = $this->grading->finalize($attempt, $actor);

            $this->audit('LMS_QUIZ_ATTEMPT_SUBMITTED', $attempt->quiz?->activity, [
                'attempt_id' => $attempt->id,
                'expired' => $expired,
                'needs_manual_grading' => (bool) $final->needs_manual_grading,
            ]);

            return $final;
        });
    }

    /**
     * Tutup percobaan yang waktunya habis (auto submit server-side, §27).
     */
    public function expireIfNeeded(LmsQuizAttempt $attempt): ?LmsQuizAttempt
    {
        if (! $attempt->isInProgress() || ! $attempt->hasExpired()) {
            return null;
        }

        return $this->submit($attempt, null, expired: true);
    }

    /** Percobaan yang sedang berjalan (bila ada) untuk quiz + user. */
    public function currentAttempt(LmsQuiz $quiz, int $userId): ?LmsQuizAttempt
    {
        return LmsQuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->where('user_id', $userId)
            ->where('status', QuizAttemptStatus::IN_PROGRESS->value)
            ->latest('id')
            ->first();
    }

    /**
     * Payload pengerjaan kuis untuk siswa — **tanpa kunci jawaban** (§28).
     *
     * @return array<string, mixed>
     */
    public function payload(LmsQuizAttempt $attempt, bool $includeReview = false): array
    {
        $attempt->loadMissing(['questions.question.options', 'answers']);

        $quiz = $attempt->quiz;
        $activity = $quiz?->activity;
        $finished = $attempt->status->isFinished();
        $review = $includeReview && $finished ? $quiz?->reviewOptions() ?? [] : [];

        $questions = $attempt->questions->map(function (LmsQuizAttemptQuestion $item) use ($attempt, $review): array {
            $snapshot = (array) $item->snapshot;
            $answer = $attempt->answers->firstWhere('question_id', $item->question_id);
            $showCorrect = (bool) ($review['show_correct_answer'] ?? false);

            return [
                'attempt_question_id' => (int) $item->id,
                'question_id' => (int) $item->question_id,
                'sort_order' => (int) $item->sort_order,
                'marks' => (float) $item->marks,
                'question_type' => $snapshot['question_type'] ?? null,
                'question_text' => ($review['show_question'] ?? true) ? ($snapshot['question_text'] ?? null) : null,
                'options' => $this->optionsForStudent($snapshot, $attempt->quiz?->shuffle_answers ?? false, $showCorrect),
                'answer' => $answer === null ? null : [
                    'answer' => $answer->answer,
                    'answer_data' => $answer->answer_data,
                    'marks' => $answer->marks === null ? null : (float) $answer->marks,
                    'is_correct' => $showCorrect ? $answer->is_correct : null,
                    'feedback' => ($review['show_feedback'] ?? false) ? ($answer->feedback ?? ($snapshot['general_feedback'] ?? null)) : null,
                ],
                'correct_option_ids' => $showCorrect
                    ? array_values(array_map('intval', array_column(array_filter(
                        (array) ($snapshot['options'] ?? []),
                        fn (array $option) => (bool) ($option['is_correct'] ?? false),
                    ), 'id')))
                    : null,
            ];
        })->all();

        $quizConfig = $quiz === null ? [] : $this->quizzes->configPayload($quiz);

        return [
            'attempt' => [
                'id' => (int) $attempt->id,
                'quiz_id' => (int) $attempt->quiz_id,
                'attempt_number' => (int) $attempt->attempt_number,
                'status' => $attempt->status?->value,
                'status_label' => $attempt->status?->label(),
                'started_at' => $attempt->started_at?->toIso8601String(),
                'expires_at' => $attempt->expires_at?->toIso8601String(),
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                'server_time' => now()->toIso8601String(),
                'seconds_remaining' => $attempt->isInProgress() ? $attempt->secondsRemaining() : 0,
                'raw_score' => $finished ? (float) $attempt->raw_score : null,
                'maximum_score' => (float) $attempt->maximum_score,
                'final_grade' => $finished && ($review['show_score'] ?? true) ? ($attempt->final_grade === null ? null : (float) $attempt->final_grade) : null,
                'passed' => $finished ? $attempt->passed : null,
                'needs_manual_grading' => (bool) $attempt->needs_manual_grading,
            ],
            'quiz' => $quizConfig,
            'activity' => $activity === null ? null : [
                'id' => (int) $activity->id,
                'title' => (string) $activity->title,
                'instructions' => $activity->instructions,
                'course_id' => (int) $activity->course_id,
            ],
            'questions' => $questions,
            'review' => $review,
            'navigation' => $quiz?->navigation_method ?? QuizNavigation::FREE,
        ];
    }

    /**
     * Opsi untuk siswa. Kunci jawaban hanya disertakan bila review mengizinkan.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    protected function optionsForStudent(array $snapshot, bool $shuffle, bool $revealCorrect): array
    {
        $options = (array) ($snapshot['options'] ?? []);

        if ($shuffle) {
            shuffle($options);
        }

        return array_map(fn (array $option) => [
            'id' => (int) $option['id'],
            'option_key' => $option['option_key'] ?? null,
            'text' => $option['text'] ?? '',
            'is_correct' => $revealCorrect ? (bool) ($option['is_correct'] ?? false) : null,
            'match_target' => $option['match_target'] ?? null,
        ], $options);
    }

    // ------------------------------------------------------------------ validasi

    protected function assertOpen(LmsQuiz $quiz): void
    {
        $activity = $quiz->activity;

        $open = $quiz->open_at ?? $activity?->start_at;
        $close = $quiz->close_at ?? $activity?->close_at;

        if ($open !== null && $open->isFuture()) {
            throw ValidationException::withMessages([
                'quiz' => 'Kuis dibuka '.$open->translatedFormat('d F Y H:i').'.',
            ]);
        }

        if ($close !== null && $close->isPast()) {
            throw ValidationException::withMessages([
                'quiz' => 'Kuis sudah ditutup pada '.$close->translatedFormat('d F Y H:i').'.',
            ]);
        }
    }

    protected function assertAttemptsRemaining(LmsQuiz $quiz, int $userId): void
    {
        $allowed = $quiz->attemptsAllowed();

        if ($allowed === 0) {
            return;
        }

        $used = LmsQuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->where('user_id', $userId)
            ->where('status', '!=', QuizAttemptStatus::IN_PROGRESS->value)
            ->count();

        if ($used >= $allowed) {
            throw ValidationException::withMessages([
                'quiz' => 'Jumlah percobaan kuis sudah habis ('.$allowed.'x).',
            ]);
        }
    }

    protected function assertPassword(LmsQuiz $quiz, ?string $password): void
    {
        if (! $quiz->require_password) {
            return;
        }

        if ($quiz->quiz_password === null || $password === null || ! hash_equals((string) $quiz->quiz_password, (string) $password)) {
            throw ValidationException::withMessages(['password' => 'Kata sandi kuis tidak sesuai.']);
        }
    }

    /** Batas waktu diperiksa di server setiap kali menyentuh percobaan (§27). */
    protected function assertOpenForWriting(LmsQuizAttempt $attempt): void
    {
        if (! $attempt->isInProgress()) {
            throw ValidationException::withMessages(['attempt' => 'Percobaan kuis ini sudah selesai.']);
        }

        if ($attempt->hasExpired()) {
            $this->submit($attempt, null, expired: true);

            throw ValidationException::withMessages([
                'attempt' => 'Waktu pengerjaan habis. Jawaban yang tersimpan sudah dikumpulkan otomatis.',
            ]);
        }
    }

    protected function assertNotExpired(LmsQuizAttempt $attempt): void
    {
        if ($attempt->hasExpired()) {
            $this->submit($attempt, null, expired: true);

            throw ValidationException::withMessages([
                'attempt' => 'Waktu pengerjaan habis. Silakan mulai percobaan baru bila masih tersedia.',
            ]);
        }
    }

    /** Navigasi SEQUENTIAL melarang melompati soal yang belum dijawab (§13). */
    protected function assertNavigationAllows(LmsQuizAttempt $attempt, LmsQuizAttemptQuestion $target): void
    {
        if (($attempt->quiz?->navigation_method ?? QuizNavigation::FREE) !== QuizNavigation::SEQUENTIAL) {
            return;
        }

        $answered = $attempt->answers()->pluck('question_id')->map(fn ($id) => (int) $id)->all();

        $openBefore = $attempt->questions()
            ->where('sort_order', '<', $target->sort_order)
            ->whereNotIn('question_id', $answered)
            ->exists();

        if ($openBefore) {
            throw ValidationException::withMessages([
                'question_id' => 'Jawab soal sebelumnya terlebih dahulu (navigasi berurutan).',
            ]);
        }
    }

    /** @param array<string, mixed> $payload */
    protected function textAnswer(array $payload): ?string
    {
        if (array_key_exists('text', $payload)) {
            return (string) $payload['text'];
        }

        if (array_key_exists('answer', $payload) && is_string($payload['answer'])) {
            return $payload['answer'];
        }

        return null;
    }

    /** Ringkasan percobaan untuk tabel guru. */
    public function summary(LmsQuizAttempt $attempt): array
    {
        return [
            'id' => (int) $attempt->id,
            'user_id' => (int) $attempt->user_id,
            'attempt_number' => (int) $attempt->attempt_number,
            'status' => $attempt->status?->value,
            'status_label' => $attempt->status?->label(),
            'started_at' => $attempt->started_at?->toIso8601String(),
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            'raw_score' => (float) $attempt->raw_score,
            'maximum_score' => (float) $attempt->maximum_score,
            'final_grade' => $attempt->final_grade === null ? null : (float) $attempt->final_grade,
            'passed' => $attempt->passed,
            'needs_manual_grading' => (bool) $attempt->needs_manual_grading,
            'duration_minutes' => $attempt->started_at !== null && $attempt->submitted_at !== null
                ? (int) $attempt->started_at->diffInMinutes($attempt->submitted_at)
                : null,
        ];
    }

    /** Percobaan seorang siswa (untuk halaman hasil & wali murid). */
    public function forStudent(LmsQuiz $quiz, int $userId): \Illuminate\Support\Collection
    {
        return LmsQuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->where('user_id', $userId)
            ->orderBy('attempt_number')
            ->get()
            ->map(fn (LmsQuizAttempt $attempt) => $this->summary($attempt));
    }

    /** Kadaluarsa dipakai oleh penjadwal / pemeriksaan berkala. */
    public function expireStale(int $limit = 100): int
    {
        $attempts = LmsQuizAttempt::query()
            ->where('status', QuizAttemptStatus::IN_PROGRESS->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', Carbon::now())
            ->limit($limit)
            ->get();

        $count = 0;

        foreach ($attempts as $attempt) {
            $this->submit($attempt, null, expired: true);
            $count++;
        }

        return $count;
    }
}
