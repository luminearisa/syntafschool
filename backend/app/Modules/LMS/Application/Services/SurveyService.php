<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Contracts\ActivityConfiguratorInterface;
use App\Modules\LMS\Domain\Enums\SurveyQuestionType;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsSurvey;
use App\Modules\LMS\Models\LmsSurveyQuestion;
use App\Modules\LMS\Models\LmsSurveyResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Survei umpan balik pembelajaran (§39).
 *
 * Mode anonim benar-benar anonim: `user_id` tidak disimpan, hanya
 * `submission_key` acak yang mengelompokkan jawaban satu pengisian.
 */
class SurveyService implements ActivityConfiguratorInterface
{
    use LogsLmsAudit;

    public function __construct(private readonly ActivityCompletionService $completion) {}

    public function createForActivity(LmsActivity $activity, array $data): void
    {
        $survey = LmsSurvey::create([
            'activity_id' => $activity->id,
            'anonymous' => $data['anonymous'] ?? false,
            'allow_multiple_submissions' => $data['allow_multiple_submissions'] ?? false,
            'show_results_to_students' => $data['show_results_to_students'] ?? false,
            'settings' => $data['survey_settings'] ?? null,
        ]);

        foreach ((array) ($data['questions'] ?? []) as $index => $question) {
            $this->createQuestion($survey, is_array($question) ? $question : ['text' => (string) $question, 'sort_order' => $index + 1]);
        }
    }

    public function updateForActivity(LmsActivity $activity, array $data): void
    {
        $survey = $this->config($activity);

        if ($survey === null) {
            return;
        }

        $survey->fill(collect($data)->only([
            'anonymous', 'allow_multiple_submissions', 'show_results_to_students',
        ])->all());

        if (array_key_exists('survey_settings', $data)) {
            $survey->settings = $data['survey_settings'];
        }

        $survey->save();
    }

    public function duplicateForActivity(LmsActivity $source, LmsActivity $copy): void
    {
        $survey = $this->config($source);

        if ($survey === null) {
            return;
        }

        // Jawaban tidak disalin (§44).
        $this->createForActivity($copy, array_merge($survey->toArray(), [
            'survey_settings' => $survey->settings,
            'questions' => $survey->questions->map(fn (LmsSurveyQuestion $question) => [
                'question_type' => $question->question_type?->value,
                'text' => $question->text,
                'options' => $question->options,
                'rating_min' => $question->rating_min,
                'rating_max' => $question->rating_max,
                'is_required' => $question->is_required,
                'sort_order' => $question->sort_order,
            ])->all(),
        ]));
    }

    public function config(LmsActivity $activity): ?LmsSurvey
    {
        return $activity->survey ?? $activity->load('survey')->survey;
    }

    public function ensureConfig(LmsActivity $activity): LmsSurvey
    {
        $survey = $this->config($activity);

        if ($survey === null) {
            $this->createForActivity($activity, []);
            $survey = $this->config($activity->fresh('survey'));
        }

        return $survey;
    }

    /** @param array<string, mixed> $data */
    public function createQuestion(LmsSurvey $survey, array $data): LmsSurveyQuestion
    {
        $type = SurveyQuestionType::tryFrom((string) ($data['question_type'] ?? SurveyQuestionType::RATING->value));

        if ($type === null) {
            throw ValidationException::withMessages(['question_type' => 'Tipe pertanyaan survei tidak dikenal.']);
        }

        return $survey->questions()->create([
            'question_type' => $type->value,
            'text' => (string) $data['text'],
            'options' => $type->needsOptions() ? array_values((array) ($data['options'] ?? [])) : null,
            'rating_min' => $data['rating_min'] ?? 1,
            'rating_max' => $data['rating_max'] ?? 5,
            'is_required' => $data['is_required'] ?? true,
            'sort_order' => $data['sort_order'] ?? ((int) $survey->questions()->max('sort_order') + 1),
        ]);
    }

    /** @param array<string, mixed> $data */
    public function updateQuestion(LmsSurveyQuestion $question, array $data): LmsSurveyQuestion
    {
        $question->fill(collect($data)->only([
            'question_type', 'text', 'options', 'rating_min', 'rating_max', 'is_required', 'sort_order',
        ])->all())->save();

        return $question->fresh();
    }

    public function deleteQuestion(LmsSurveyQuestion $question): void
    {
        $survey = $question->survey;

        if ($survey !== null && $survey->questions()->count() <= 1) {
            throw ValidationException::withMessages([
                'question' => 'Survei harus memiliki minimal satu pertanyaan.',
            ]);
        }

        $question->delete();
    }

    /**
     * Isi survei (§39).
     *
     * @param  list<array{question_id: int, response_text?: string|null, response_value?: float|int|null, response_data?: mixed}>  $answers
     * @return array<string, mixed>
     */
    public function submit(LmsActivity $activity, LmsEnrollment $enrollment, array $answers): array
    {
        $survey = $this->ensureConfig($activity);

        if (! $survey->allow_multiple_submissions && $this->hasSubmitted($survey, $enrollment->user_id)) {
            throw ValidationException::withMessages([
                'survey' => 'Anda sudah mengisi survei ini.',
            ]);
        }

        $questions = $survey->questions()->get()->keyBy('id');
        $submitted = collect($answers)->keyBy(fn (array $answer) => (int) ($answer['question_id'] ?? 0));

        foreach ($questions as $question) {
            $answer = $submitted->get((int) $question->id);
            $empty = $answer === null
                || (trim((string) ($answer['response_text'] ?? '')) === ''
                    && $answer['response_value'] === null
                    && empty($answer['response_data']));

            if ($question->is_required && $empty) {
                throw ValidationException::withMessages([
                    'answers' => 'Pertanyaan "'.Str::limit($question->text, 40).'" wajib diisi.',
                ]);
            }
        }

        $key = (string) Str::uuid();

        return DB::transaction(function () use ($survey, $questions, $submitted, $enrollment, $key, $activity): array {
            foreach ($questions as $question) {
                $answer = $submitted->get((int) $question->id);

                if ($answer === null) {
                    continue;
                }

                LmsSurveyResponse::create([
                    'survey_id' => $survey->id,
                    'question_id' => $question->id,
                    // Anonim: identitas tidak disimpan sama sekali (§39).
                    'user_id' => $survey->isAnonymous() ? null : $enrollment->user_id,
                    'student_id' => $survey->isAnonymous() ? null : $enrollment->student_id,
                    'submission_key' => $key,
                    'response_text' => $answer['response_text'] ?? null,
                    'response_value' => $answer['response_value'] ?? null,
                    'response_data' => $answer['response_data'] ?? null,
                ]);
            }

            $this->completion->complete($activity, $enrollment, null, 'survey_submitted', $enrollment->user_id, [
                'submission_key' => $key,
            ]);

            $this->audit('LMS_SURVEY_SUBMITTED', $activity, [
                'submission_key' => $key,
                'anonymous' => $survey->isAnonymous(),
            ]);

            return [
                'submitted' => true,
                'anonymous' => $survey->isAnonymous(),
                'submission_key' => $survey->isAnonymous() ? null : $key,
                'results' => $survey->show_results_to_students ? $this->results($survey) : null,
            ];
        });
    }

    /**
     * Tampilan survei untuk siswa (§39).
     *
     * @return array<string, mixed>
     */
    public function view(LmsActivity $activity, ?int $userId): array
    {
        $survey = $this->ensureConfig($activity);

        return [
            'survey' => $this->configPayload($survey),
            'questions' => $survey->questions->map(fn (LmsSurveyQuestion $question) => [
                'id' => (int) $question->id,
                'question_type' => $question->question_type?->value,
                'question_type_label' => $question->question_type?->label(),
                'text' => (string) $question->text,
                'options' => $question->options,
                'rating_min' => (int) $question->rating_min,
                'rating_max' => (int) $question->rating_max,
                'is_required' => (bool) $question->is_required,
                'sort_order' => (int) $question->sort_order,
            ])->all(),
            'has_submitted' => $userId !== null && ! $survey->allow_multiple_submissions && $this->hasSubmitted($survey, $userId),
            'results' => $survey->show_results_to_students ? $this->results($survey) : null,
        ];
    }

    public function hasSubmitted(LmsSurvey $survey, int $userId): bool
    {
        return LmsSurveyResponse::query()
            ->where('survey_id', $survey->id)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Rekap hasil survei (§39) — anonim tetap tanpa identitas.
     *
     * @return array<string, mixed>
     */
    public function results(LmsSurvey $survey): array
    {
        return [
            'anonymous' => $survey->isAnonymous(),
            'responses' => LmsSurveyResponse::query()
                ->where('survey_id', $survey->id)
                ->distinct()
                ->count('submission_key'),
            'questions' => $survey->questions->map(function (LmsSurveyQuestion $question): array {
                $responses = LmsSurveyResponse::query()->where('question_id', $question->id)->get();

                $base = [
                    'id' => (int) $question->id,
                    'text' => (string) $question->text,
                    'question_type' => $question->question_type?->value,
                    'answers' => $responses->count(),
                ];

                if ($question->question_type === SurveyQuestionType::RATING) {
                    return $base + [
                        'average' => $responses->isEmpty() ? null : round((float) $responses->avg('response_value'), 2),
                        'distribution' => $responses->groupBy(fn (LmsSurveyResponse $response) => (int) $response->response_value)
                            ->map->count()
                            ->sortKeys()
                            ->all(),
                    ];
                }

                if ($question->question_type === SurveyQuestionType::MULTIPLE_CHOICE
                    || $question->question_type === SurveyQuestionType::YES_NO) {
                    $counts = $responses->groupBy('response_text')->map->count()->all();

                    return $base + [
                        'distribution' => $counts,
                        'percentages' => collect($counts)->map(
                            fn (int $count) => $responses->count() > 0 ? round($count / $responses->count() * 100, 2) : 0.0,
                        )->all(),
                    ];
                }

                return $base + [
                    'comments' => $responses->pluck('response_text')->filter()->take(50)->values()->all(),
                ];
            })->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function configPayload(LmsSurvey $survey): array
    {
        return [
            'id' => (int) $survey->id,
            'anonymous' => (bool) $survey->anonymous,
            'allow_multiple_submissions' => (bool) $survey->allow_multiple_submissions,
            'show_results_to_students' => (bool) $survey->show_results_to_students,
            'questions_count' => $survey->questions()->count(),
            'settings' => $survey->settings,
        ];
    }

    /** Statistik survei untuk guru. */
    public function statistics(LmsActivity $activity): array
    {
        $survey = $this->ensureConfig($activity);

        $participants = LmsEnrollment::query()
            ->where('course_id', $activity->course_id)
            ->where('role', 'STUDENT')
            ->whereIn('status', ['ACTIVE', 'COMPLETED'])
            ->count();

        $results = $this->results($survey);

        return array_merge($results, [
            'participants' => $participants,
            'response_rate' => $participants > 0 ? round($results['responses'] / $participants * 100, 2) : null,
        ]);
    }
}
