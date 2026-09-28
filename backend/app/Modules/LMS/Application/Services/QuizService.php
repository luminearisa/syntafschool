<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Contracts\ActivityConfiguratorInterface;
use App\Modules\LMS\Domain\Enums\ActivityGradeSource;
use App\Modules\LMS\Domain\Enums\QuestionType;
use App\Modules\LMS\Domain\Enums\QuizAttemptStatus;
use App\Modules\LMS\Domain\Enums\QuizGradeStrategy;
use App\Modules\LMS\Domain\Enums\QuizNavigation;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsQuestion;
use App\Modules\LMS\Models\LmsQuestionCategory;
use App\Modules\LMS\Models\LmsQuiz;
use App\Modules\LMS\Models\LmsQuizAttempt;
use App\Modules\LMS\Models\LmsQuizAttemptQuestion;
use App\Modules\LMS\Models\LmsQuizQuestion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Kuis — konfigurasi, pemilihan soal, dan statistik (§12–§14, §23–§24, §29–§31).
 *
 * Menyimpan soal (manual maupun slot acak), menghitung statistik, dan menyusun
 * tampilan siswa **tanpa pernah membocorkan kunci jawaban** (§28).
 */
class QuizService implements ActivityConfiguratorInterface
{
    use LogsLmsAudit;

    public function __construct(private readonly QuestionService $questions) {}

    public function createForActivity(LmsActivity $activity, array $data): void
    {
        LmsQuiz::create([
            'activity_id' => $activity->id,
            'time_limit_minutes' => $data['time_limit_minutes'] ?? null,
            'attempts_allowed' => $data['attempts_allowed'] ?? 1,
            'grade_strategy' => $data['grade_strategy'] ?? QuizGradeStrategy::HIGHEST->value,
            'shuffle_questions' => $data['shuffle_questions'] ?? false,
            'shuffle_answers' => $data['shuffle_answers'] ?? false,
            'show_one_question_at_time' => $data['show_one_question_at_time'] ?? false,
            'navigation_method' => $data['navigation_method'] ?? QuizNavigation::FREE->value,
            'maximum_grade' => $data['maximum_grade'] ?? 100,
            'passing_grade' => $data['passing_grade'] ?? null,
            'questions_per_page' => $data['questions_per_page'] ?? null,
            'require_password' => $data['require_password'] ?? false,
            'quiz_password' => $data['quiz_password'] ?? null,
            'open_at' => $data['open_at'] ?? null,
            'close_at' => $data['close_at'] ?? null,
            'review_options' => $data['review_options'] ?? null,
            'settings' => $data['quiz_settings'] ?? null,
        ]);
    }

    public function updateForActivity(LmsActivity $activity, array $data): void
    {
        $quiz = $this->config($activity);

        if ($quiz === null) {
            return;
        }

        $quiz->fill(collect($data)->only([
            'time_limit_minutes', 'attempts_allowed', 'grade_strategy', 'shuffle_questions',
            'shuffle_answers', 'show_one_question_at_time', 'navigation_method', 'maximum_grade',
            'passing_grade', 'questions_per_page', 'require_password', 'quiz_password',
            'open_at', 'close_at', 'review_options',
        ])->all());

        if (array_key_exists('quiz_settings', $data)) {
            $quiz->settings = $data['quiz_settings'];
        }

        $quiz->save();
    }

    public function duplicateForActivity(LmsActivity $source, LmsActivity $copy): void
    {
        $quiz = $this->config($source);

        if ($quiz === null) {
            return;
        }

        $this->createForActivity($copy, array_merge($quiz->toArray(), ['quiz_settings' => $quiz->settings]));

        $newQuiz = $this->config($copy);

        if ($newQuiz === null) {
            return;
        }

        // Referensi soal disalin; riwayat attempt tidak (§44).
        foreach ($source->quiz->questions as $question) {
            $newQuiz->questions()->create([
                'question_id' => $question->question_id,
                'category_id' => $question->category_id,
                'question_count' => $question->question_count,
                'marks' => $question->marks,
                'sort_order' => $question->sort_order,
            ]);
        }
    }

    public function config(LmsActivity $activity): ?LmsQuiz
    {
        return $activity->quiz ?? $activity->load('quiz')->quiz;
    }

    public function ensureConfig(LmsActivity $activity): LmsQuiz
    {
        $quiz = $this->config($activity);

        if ($quiz === null) {
            $this->createForActivity($activity, []);
            $quiz = $this->config($activity->fresh('quiz'));
        }

        return $quiz;
    }

    // ------------------------------------------------------------------ pemilihan soal

    /**
     * Tambah soal manual ke kuis (§23).
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function addQuestions(LmsQuiz $quiz, array $items): Collection
    {
        foreach (array_values($items) as $index => $item) {
            $question = null;

            if (! empty($item['question_id'])) {
                $question = LmsQuestion::query()->findOrFail((int) $item['question_id']);
            }

            $category = null;

            if (! empty($item['category_id'])) {
                $category = LmsQuestionCategory::query()->findOrFail((int) $item['category_id']);
            }

            if ($question === null && $category === null) {
                throw ValidationException::withMessages([
                    'question_id' => 'Pilih soal atau kategori sumber soal acak.',
                ]);
            }

            $quiz->questions()->create([
                'question_id' => $question?->id,
                'category_id' => $category?->id,
                'question_count' => $question === null ? (int) ($item['question_count'] ?? 1) : null,
                'marks' => $item['marks'] ?? ($question?->default_mark ?? 1),
                'sort_order' => (int) $quiz->questions()->max('sort_order') + $index + 1,
            ]);
        }

        $this->audit('LMS_QUIZ_QUESTIONS_ADDED', $quiz->activity, ['count' => count($items)]);

        return $quiz->questions()->with(['question.options', 'category'])->get();
    }

    public function removeQuestion(LmsQuiz $quiz, LmsQuizQuestion $slot): void
    {
        if ((int) $slot->quiz_id !== (int) $quiz->id) {
            throw ValidationException::withMessages(['slot' => 'Soal ini bukan milik kuis tersebut.']);
        }

        $slot->delete();
    }

    /** @param list<int> $slotIds */
    public function reorderQuestions(LmsQuiz $quiz, array $slotIds): void
    {
        foreach (array_values($slotIds) as $index => $id) {
            $quiz->questions()->whereKey($id)->update(['sort_order' => $index + 1]);
        }
    }

    public function updateSlotMark(LmsQuizQuestion $slot, float $marks): LmsQuizQuestion
    {
        if ($marks <= 0) {
            throw ValidationException::withMessages(['marks' => 'Mark soal harus lebih dari 0.']);
        }

        $slot->forceFill(['marks' => $marks])->save();

        return $slot->fresh();
    }

    /** Total mark kuis (§24). */
    public function totalMarks(LmsQuiz $quiz): float
    {
        return (float) $quiz->questions()->sum('marks');
    }

    // ------------------------------------------------------------------ tampilan

    /**
     * Tampilan kuis untuk siswa (§12, §26) — konfigurasi + status percobaan.
     *
     * @return array<string, mixed>
     */
    public function studentView(LmsActivity $activity, LmsEnrollment $enrollment): array
    {
        $quiz = $this->ensureConfig($activity);
        $userId = $enrollment->user_id;

        $attempts = LmsQuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->where('user_id', $userId)
            ->orderBy('attempt_number')
            ->get();

        $inProgress = $attempts->firstWhere('status', QuizAttemptStatus::IN_PROGRESS);

        $finished = $attempts->filter(fn (LmsQuizAttempt $attempt) => $attempt->status->isFinished());

        return [
            'quiz' => $this->configPayload($quiz, includePassword: false),
            'total_marks' => $this->totalMarks($quiz),
            'question_count' => $this->questionCount($quiz),
            'attempts' => $attempts->map(fn (LmsQuizAttempt $attempt) => [
                'id' => (int) $attempt->id,
                'attempt_number' => (int) $attempt->attempt_number,
                'status' => $attempt->status?->value,
                'status_label' => $attempt->status?->label(),
                'final_grade' => $attempt->final_grade === null ? null : (float) $attempt->final_grade,
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                'seconds_remaining' => $attempt->isInProgress() ? $attempt->secondsRemaining() : null,
            ])->all(),
            'attempts_used' => $finished->count(),
            'attempts_allowed' => $quiz->attemptsAllowed(),
            'attempts_remaining' => $quiz->attemptsAllowed() === 0
                ? null
                : max(0, $quiz->attemptsAllowed() - $finished->count()),
            'open_attempt_id' => $inProgress?->id === null ? null : (int) $inProgress->id,
            'final_grade' => $this->bestGrade($quiz, $userId),
            'review_options' => $quiz->reviewOptions(),
        ];
    }

    /** @return array<string, mixed> */
    public function configPayload(LmsQuiz $quiz, bool $includePassword = false): array
    {
        return [
            'id' => (int) $quiz->id,
            'time_limit_minutes' => $quiz->time_limit_minutes === null ? null : (int) $quiz->time_limit_minutes,
            'attempts_allowed' => $quiz->attemptsAllowed(),
            'grade_strategy' => $quiz->grade_strategy?->value,
            'grade_strategy_label' => $quiz->grade_strategy?->label(),
            'shuffle_questions' => (bool) $quiz->shuffle_questions,
            'shuffle_answers' => (bool) $quiz->shuffle_answers,
            'show_one_question_at_time' => (bool) $quiz->show_one_question_at_time,
            'navigation_method' => $quiz->navigation_method?->value,
            'maximum_grade' => (float) $quiz->maximum_grade,
            'passing_grade' => $quiz->passing_grade === null ? null : (float) $quiz->passing_grade,
            'questions_per_page' => $quiz->questions_per_page === null ? null : (int) $quiz->questions_per_page,
            'require_password' => (bool) $quiz->require_password,
            'password' => $includePassword ? $quiz->quiz_password : null,
            'open_at' => $quiz->open_at?->toIso8601String(),
            'close_at' => $quiz->close_at?->toIso8601String(),
            'review_options' => $quiz->reviewOptions(),
            'settings' => $quiz->settings,
        ];
    }

    /** Daftar soal untuk guru (lengkap dengan kunci). */
    public function builderPayload(LmsActivity $activity): array
    {
        $quiz = $this->ensureConfig($activity);

        return [
            'quiz' => $this->configPayload($quiz, includePassword: true),
            'total_marks' => $this->totalMarks($quiz),
            'slots' => $quiz->questions()->with(['question.options', 'category'])->get()
                ->map(fn (LmsQuizQuestion $slot) => [
                    'id' => (int) $slot->id,
                    'sort_order' => (int) $slot->sort_order,
                    'marks' => (float) $slot->marks,
                    'is_random' => $slot->isRandomSlot(),
                    'question_count' => $slot->question_count === null ? null : (int) $slot->question_count,
                    'category' => $slot->category === null ? null : [
                        'id' => (int) $slot->category->id,
                        'name' => (string) $slot->category->name,
                    ],
                    'question' => $slot->question === null ? null : [
                        'id' => (int) $slot->question->id,
                        'name' => (string) $slot->question->name,
                        'question_type' => $slot->question->question_type?->value,
                        'question_type_label' => $slot->question->question_type?->label(),
                        'difficulty' => $slot->question->difficulty?->value,
                        'version' => (int) $slot->question->version,
                        'default_mark' => (float) $slot->question->default_mark,
                        'options_count' => $slot->question->options->count(),
                    ],
                ])->all(),
        ];
    }

    public function questionCount(LmsQuiz $quiz): int
    {
        return (int) $quiz->questions()->get()->sum(fn (LmsQuizQuestion $slot) => $slot->isRandomSlot()
            ? (int) $slot->question_count
            : 1);
    }

    // ------------------------------------------------------------------ statistik (§31)

    /**
     * Statistik kuis: percobaan, rata-rata, lulus, dan statistik per soal.
     *
     * @return array<string, mixed>
     */
    public function statistics(LmsActivity $activity): array
    {
        $quiz = $this->ensureConfig($activity);

        $attempts = LmsQuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->where('status', '!=', QuizAttemptStatus::IN_PROGRESS->value)
            ->get();

        $graded = $attempts->whereNotNull('final_grade');

        $participants = LmsEnrollment::query()
            ->where('course_id', $activity->course_id)
            ->where('role', 'STUDENT')
            ->whereIn('status', ['ACTIVE', 'COMPLETED'])
            ->count();

        $passed = $graded->where('passed', true)->count();

        return [
            'participants' => $participants,
            'attempts' => $attempts->count(),
            'students_attempted' => $attempts->pluck('user_id')->unique()->count(),
            'average' => $graded->isEmpty() ? null : round((float) $graded->avg('final_grade'), 2),
            'highest' => $graded->isEmpty() ? null : (float) $graded->max('final_grade'),
            'lowest' => $graded->isEmpty() ? null : (float) $graded->min('final_grade'),
            'pass_rate' => $graded->isEmpty() ? null : round($passed / $graded->count() * 100, 2),
            'completion_rate' => $participants > 0
                ? round($attempts->pluck('user_id')->unique()->count() / $participants * 100, 2)
                : null,
            'pending_manual_grading' => $this->pendingManualGradingCount($activity),
            'maximum_grade' => (float) $quiz->maximum_grade,
            'questions' => $this->questionStatistics($quiz),
        ];
    }

    /**
     * Statistik per soal (§31): jumlah jawaban, benar/salah, rata-rata mark.
     *
     * @return list<array<string, mixed>>
     */
    public function questionStatistics(LmsQuiz $quiz): array
    {
        $slots = $quiz->questions()->with('question')->get();

        return $slots->map(function (LmsQuizQuestion $slot): array {
            $answers = \App\Modules\LMS\Models\LmsQuizAnswer::query()
                ->when($slot->question_id !== null, fn ($q) => $q->where('question_id', $slot->question_id))
                ->when($slot->question_id === null && $slot->category_id !== null, fn ($q) => $q->whereHas(
                    'question',
                    fn ($qq) => $qq->whereIn('category_id', LmsQuestionCategory::query()->find($slot->category_id)?->descendantIds() ?? []),
                ))
                ->get();

            $total = $answers->count();
            $correct = $answers->where('is_correct', true)->count();
            $incorrect = $answers->where('is_correct', false)->count();

            return [
                'slot_id' => (int) $slot->id,
                'question_id' => $slot->question_id === null ? null : (int) $slot->question_id,
                'title' => $slot->question?->name ?? ($slot->category?->name ?? 'Soal acak'),
                'is_random' => $slot->isRandomSlot(),
                'question_type' => $slot->question?->question_type?->value,
                'marks' => (float) $slot->marks,
                'answers' => $total,
                'correct' => $correct,
                'incorrect' => $incorrect,
                'correct_percent' => $total > 0 ? round($correct / $total * 100, 2) : null,
                'incorrect_percent' => $total > 0 ? round($incorrect / $total * 100, 2) : null,
                'average_marks' => $total > 0 ? round((float) $answers->avg('marks'), 2) : null,
            ];
        })->all();
    }

    /** Nilai terbaik seorang siswa menurut strategi kuis (§30). */
    public function bestGrade(LmsQuiz $quiz, int $userId): ?float
    {
        $attempts = LmsQuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->where('user_id', $userId)
            ->whereNotNull('final_grade')
            ->orderBy('attempt_number')
            ->get();

        if ($attempts->isEmpty()) {
            return null;
        }

        return match ($quiz->grade_strategy ?? QuizGradeStrategy::HIGHEST) {
            QuizGradeStrategy::HIGHEST => (float) $attempts->max('final_grade'),
            QuizGradeStrategy::LATEST => (float) $attempts->last()->final_grade,
            QuizGradeStrategy::FIRST => (float) $attempts->first()->final_grade,
            QuizGradeStrategy::AVERAGE => round((float) $attempts->avg('final_grade'), 2),
        };
    }

    /** Jumlah percobaan yang masih menunggu penilaian manual (§50). */
    public function pendingManualGradingCount(LmsActivity $activity): int
    {
        $quiz = $this->ensureConfig($activity);

        return LmsQuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->where('status', QuizAttemptStatus::MANUAL_GRADING->value)
            ->count();
    }

    /**
     * Umpan balik per tipe soal untuk guru (membantu penilaian manual esai).
     *
     * @return array<string, mixed>
     */
    public function gradingGuide(LmsQuiz $quiz): array
    {
        return [
            'auto_gradable_types' => array_values(array_map(
                fn (QuestionType $type) => $type->value,
                array_filter(QuestionType::cases(), fn (QuestionType $type) => $type->isAutoGradable()),
            )),
            'manual_types' => [QuestionType::ESSAY->value],
            'source' => ActivityGradeSource::QUIZ->value,
        ];
    }
}
