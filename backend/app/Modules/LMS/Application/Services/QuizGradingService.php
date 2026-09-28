<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\QuizAttemptStatus;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsActivityGrade;
use App\Modules\LMS\Models\LmsQuizAttempt;
use App\Modules\LMS\Models\LmsQuizAnswer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penilaian kuis (§20, §25–§30, §54).
 *
 * Soal otomatis dinilai server-side memakai snapshot soal pada attempt, soal
 * esai menunggu guru, dan nilai akhir dinormalisasi ke `maximum_grade` kuis.
 */
class QuizGradingService
{
    use LogsLmsAudit;

    public function __construct(
        private readonly QuestionGradingService $grader,
        private readonly ActivityGradeService $grades,
        private readonly ActivityCompletionService $completion,
    ) {}

    /**
     * Nilai seluruh soal yang bisa dinilai otomatis.
     *
     * @return array{auto: int, manual: int, raw_score: float, maximum_score: float}
     */
    public function autoGrade(LmsQuizAttempt $attempt): array
    {
        $attempt->loadMissing(['questions.question', 'answers']);

        $raw = 0.0;
        $maximum = 0.0;
        $auto = 0;
        $manual = 0;

        foreach ($attempt->questions as $attemptQuestion) {
            $maximum += (float) $attemptQuestion->marks;

            $answer = $attempt->answers->firstWhere('question_id', $attemptQuestion->question_id);

            if ($answer === null) {
                // Belum dijawab: soal esai tetap menunggu guru, lainnya bernilai 0.
                if (($attemptQuestion->snapshot['question_type'] ?? null) === 'ESSAY') {
                    $manual++;
                }

                continue;
            }

            $result = $this->grader->gradeSnapshot(
                (array) $attemptQuestion->snapshot,
                $this->responseFrom($answer),
                (float) $attemptQuestion->marks,
            );

            $answer->forceFill([
                'marks' => $result['marks'],
                'is_correct' => $result['is_correct'],
                'feedback' => $result['feedback'] ?? $answer->feedback,
                'graded_at' => now(),
            ])->save();

            $raw += (float) $result['marks'];

            if ($result['needs_manual']) {
                $manual++;
            } else {
                $auto++;
            }
        }

        $attempt->forceFill([
            'raw_score' => round($raw, 2),
            'maximum_score' => round($maximum, 2),
            'needs_manual_grading' => $manual > 0,
        ])->save();

        return ['auto' => $auto, 'manual' => $manual, 'raw_score' => round($raw, 2), 'maximum_score' => round($maximum, 2)];
    }

    /**
     * Selesaikan penilaian: normalisasi, status, nilai aktivitas, penyelesaian.
     */
    public function finalize(LmsQuizAttempt $attempt, ?LmsActor $actor = null): LmsQuizAttempt
    {
        $quiz = $attempt->quiz()->firstOrFail();
        $activity = $quiz->activity()->firstOrFail();

        return DB::transaction(function () use ($attempt, $quiz, $activity, $actor): LmsQuizAttempt {
            $attempt->loadMissing('answers');

            $raw = (float) $attempt->answers->sum('marks');
            $maximum = (float) $attempt->maximum_score;

            $maximumGrade = (float) $quiz->maximum_grade;
            $pending = (bool) $attempt->needs_manual_grading;

            // Selama masih ada esai yang belum dinilai, nilai akhir sengaja
            // dibiarkan kosong: nilai baru sah setelah guru menilai (§20).
            $final = $pending
                ? null
                : ($maximum > 0 ? round($raw / $maximum * $maximumGrade, 2) : 0.0);
            $passed = $pending || $quiz->passing_grade === null
                ? null
                : $final >= (float) $quiz->passing_grade;

            $wasExpired = (bool) (($attempt->metadata ?? [])['expired'] ?? false)
                || $attempt->status === QuizAttemptStatus::EXPIRED;

            $attempt->forceFill([
                'raw_score' => round($raw, 2),
                'final_grade' => $final,
                'passed' => $passed,
                // Percobaan yang waktunya habis tetap ditandai EXPIRED walau
                // sudah dinilai otomatis, supaya guru tahu konteksnya (§27).
                'status' => $pending
                    ? QuizAttemptStatus::MANUAL_GRADING->value
                    : ($wasExpired ? QuizAttemptStatus::EXPIRED->value : QuizAttemptStatus::GRADED->value),
                'graded_at' => now(),
                'graded_by' => $actor?->id,
            ])->save();

            // Hanya nilai yang sudah final yang masuk struktur nilai LMS (§46).
            if (! $pending) {
                $this->grades->record($activity, $attempt->user_id, [
                    'grade' => $final,
                    'raw_grade' => round($raw, 2),
                    'maximum_grade' => $maximumGrade,
                    'passed' => $passed,
                    'graded_by' => $actor?->id,
                    'student_id' => $attempt->student_id,
                    'enrollment_id' => $attempt->enrollment_id,
                    'source' => 'QUIZ',
                ]);
            }

            $enrollment = $attempt->enrollment;

            if ($enrollment !== null) {
                $this->completion->complete($activity, $enrollment, $final, 'quiz_submitted', $attempt->user_id, [
                    'attempt_id' => $attempt->id,
                ]);
            }

            $this->audit('LMS_QUIZ_ATTEMPT_GRADED', $activity, [
                'attempt_id' => $attempt->id,
                'final_grade' => $final,
                'needs_manual_grading' => (bool) $attempt->needs_manual_grading,
            ]);

            return $attempt->fresh(['answers', 'questions']);
        });
    }

    /**
     * Penilaian manual satu jawaban esai (§20).
     */
    public function gradeAnswer(LmsQuizAnswer $answer, float $marks, ?string $feedback, LmsActor $actor): LmsQuizAnswer
    {
        $attempt = $answer->attempt;

        if ($attempt === null) {
            throw ValidationException::withMessages(['answer' => 'Jawaban tidak memiliki percobaan yang sah.']);
        }

        $attemptQuestion = $attempt->questions()->where('question_id', $answer->question_id)->first();
        $maximum = (float) ($attemptQuestion?->marks ?? 0);

        if ($marks < 0 || $marks > $maximum) {
            throw ValidationException::withMessages([
                'marks' => 'Mark harus berada di antara 0 dan '.$maximum.'.',
            ]);
        }

        $answer->forceFill([
            'marks' => $marks,
            'is_correct' => $marks >= $maximum,
            'feedback' => $feedback ?? $answer->feedback,
            'graded_by' => $actor->id,
            'graded_at' => now(),
        ])->save();

        // Bila seluruh jawaban sudah dinilai, attempt dapat difinalkan.
        $attempt->loadMissing('answers');
        $pending = $attempt->answers->whereNull('graded_at')->count();

        if ($pending === 0) {
            $attempt->forceFill(['needs_manual_grading' => false])->save();
            $this->finalize($attempt, $actor);
        }

        $this->audit('LMS_QUIZ_ANSWER_GRADED', $attempt->quiz?->activity, [
            'attempt_id' => $attempt->id,
            'answer_id' => $answer->id,
            'marks' => $marks,
        ]);

        return $answer->fresh();
    }

    /**
     * Nilai akhir lintas percobaan menurut strategi kuis (§30).
     *
     * @return array{strategy: string, final_grade: float|null, attempts: int}
     */
    public function aggregate(LmsQuizAttempt $attempt): array
    {
        $quiz = $attempt->quiz()->firstOrFail();
        $attempts = LmsQuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->where('user_id', $attempt->user_id)
            ->whereNotNull('final_grade')
            ->orderBy('attempt_number')
            ->get();

        $final = match ($quiz->grade_strategy) {
            \App\Modules\LMS\Domain\Enums\QuizGradeStrategy::HIGHEST => (float) $attempts->max('final_grade'),
            \App\Modules\LMS\Domain\Enums\QuizGradeStrategy::LATEST => (float) $attempts->last()?->final_grade,
            \App\Modules\LMS\Domain\Enums\QuizGradeStrategy::FIRST => (float) $attempts->first()?->final_grade,
            \App\Modules\LMS\Domain\Enums\QuizGradeStrategy::AVERAGE => round((float) $attempts->avg('final_grade'), 2),
        };

        return [
            'strategy' => $quiz->grade_strategy?->value,
            'final_grade' => $attempts->isEmpty() ? null : $final,
            'attempts' => $attempts->count(),
        ];
    }

    /** Nilai yang tersimpan pada struktur nilai LMS untuk aktivitas kuis. */
    public function activityGrade(LmsQuizAttempt $attempt): ?LmsActivityGrade
    {
        $activityId = $attempt->quiz?->activity_id;

        return $activityId === null
            ? null
            : $this->grades->forActivityUser($attempt->quiz->activity, $attempt->user_id);
    }

    /**
     * Jawaban siswa → payload penilaian.
     *
     * @return array<string, mixed>
     */
    protected function responseFrom(LmsQuizAnswer $answer): array
    {
        $data = (array) ($answer->answer_data ?? []);

        if ($answer->answer !== null && ! array_key_exists('text', $data)) {
            $data['text'] = $answer->answer;
        }

        return $data;
    }
}
