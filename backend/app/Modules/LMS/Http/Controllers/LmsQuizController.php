<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\ActivityAvailabilityService;
use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\QuizAttemptService;
use App\Modules\LMS\Application\Services\QuizService;
use App\Modules\LMS\Http\Controllers\Concerns\ResolvesActivities;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsQuiz;
use App\Modules\LMS\Models\LmsQuizAttempt;
use App\Modules\LMS\Models\LmsQuizQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kuis: tampilan siswa, builder guru, statistik, dan penilaian manual
 * (§12–§14, §23–§24, §29–§31, §53).
 */
class LmsQuizController extends LmsController
{
    use ResolvesActivities;

    public function __construct(
        private readonly QuizService $quizzes,
        private readonly QuizAttemptService $attempts,
        private readonly ActivityAvailabilityService $availability,
        private readonly CourseAccessService $access,
    ) {}

    /** Tampilan kuis untuk siswa (§12) — tanpa kunci jawaban (§28). */
    public function show(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        [$actor, $enrollment] = $this->requireEnrollment($request, $model);

        $state = $this->availability->studentState($model, $enrollment);

        if ($state['locked']) {
            return $this->successResponse(['locked' => true, 'state' => $state], $state['reason_label'] ?? 'Aktivitas terkunci');
        }

        return $this->successResponse(
            $this->quizzes->studentView($model, $enrollment) + ['state' => $state, 'locked' => false],
            'Detail kuis berhasil diambil',
        );
    }

    /** Builder guru: konfigurasi + slot soal (§13, §23). */
    public function builder(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $this->authorizeGrade($request, $model);

        return $this->successResponse(
            $this->quizzes->builderPayload($model) + [
                'statistics' => $this->quizzes->statistics($model),
            ],
            'Builder kuis berhasil diambil',
        );
    }

    /** Ubah konfigurasi kuis (§13). */
    public function updateConfig(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $this->authorizeManage($request, $model);

        $data = $this->validatePayload($request, [
            'time_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'attempts_allowed' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'grade_strategy' => ['sometimes', 'string', 'in:HIGHEST,LATEST,AVERAGE,FIRST'],
            'shuffle_questions' => ['sometimes', 'boolean'],
            'shuffle_answers' => ['sometimes', 'boolean'],
            'show_one_question_at_time' => ['sometimes', 'boolean'],
            'navigation_method' => ['sometimes', 'string', 'in:FREE,SEQUENTIAL'],
            'maximum_grade' => ['sometimes', 'numeric', 'min:1', 'max:1000'],
            'passing_grade' => ['nullable', 'numeric', 'min:0'],
            'questions_per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'open_at' => ['nullable', 'date'],
            'close_at' => ['nullable', 'date'],
            'require_password' => ['sometimes', 'boolean'],
            'quiz_password' => ['nullable', 'string', 'max:100'],
            'review_options' => ['nullable', 'array'],
            'review_options.show_score' => ['nullable', 'boolean'],
            'review_options.show_correct_answer' => ['nullable', 'boolean'],
            'review_options.show_feedback' => ['nullable', 'boolean'],
            'review_options.show_question' => ['nullable', 'boolean'],
            'review_options.show_answer' => ['nullable', 'boolean'],
        ]);

        $this->quizzes->updateForActivity($model, $data);

        return $this->successResponse(
            $this->quizzes->builderPayload($model->fresh('quiz')),
            'Konfigurasi kuis berhasil disimpan',
        );
    }

    /** Tambah soal manual atau slot acak (§23). */
    public function addQuestions(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $this->authorizeManage($request, $model);

        $data = $this->validatePayload($request, [
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.question_id' => ['nullable', 'integer', 'exists:lms_questions,id'],
            'questions.*.category_id' => ['nullable', 'integer', 'exists:lms_question_categories,id'],
            'questions.*.question_count' => ['nullable', 'integer', 'min:1', 'max:100'],
            'questions.*.marks' => ['nullable', 'numeric', 'min:0.5', 'max:100'],
        ]);

        $quiz = $this->quizzes->ensureConfig($model);
        $this->quizzes->addQuestions($quiz, $data['questions']);

        return $this->successResponse($this->quizzes->builderPayload($model->fresh('quiz')), 'Soal berhasil ditambahkan');
    }

    public function removeQuestion(Request $request, LmsQuizQuestion $slot): JsonResponse
    {
        $activity = $slot->quiz?->activity;
        $this->authorizeManage($request, $activity);

        $this->quizzes->removeQuestion($slot->quiz, $slot);

        return $this->successResponse(null, 'Soal berhasil dihapus dari kuis');
    }

    public function updateSlot(Request $request, LmsQuizQuestion $slot): JsonResponse
    {
        $activity = $slot->quiz?->activity;
        $this->authorizeManage($request, $activity);

        $data = $this->validatePayload($request, ['marks' => ['required', 'numeric', 'min:0.5', 'max:100']]);

        $this->quizzes->updateSlotMark($slot, (float) $data['marks']);

        return $this->successResponse($this->quizzes->builderPayload($activity), 'Mark soal diperbarui');
    }

    /** Statistik kuis + per soal (§31). */
    public function statistics(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $this->authorizeGrade($request, $model);

        return $this->successResponse($this->quizzes->statistics($model), 'Statistik kuis berhasil diambil');
    }

    /** Daftar percobaan siswa (guru) + antrean penilaian manual (§50). */
    public function attempts(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $this->authorizeGrade($request, $model);

        $quiz = $this->quizzes->ensureConfig($model);

        $attempts = LmsQuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('user_id'), fn ($query, $userId) => $query->where('user_id', $userId))
            ->with('user')
            ->orderByDesc('submitted_at')
            ->paginate($this->perPage($request, 25));

        return $this->successResponse(
            $attempts->through(fn (LmsQuizAttempt $attempt) => $this->attempts->summary($attempt) + [
                'student' => [
                    'user_id' => (int) $attempt->user_id,
                    'name' => $attempt->user?->name,
                ],
                'final_grade' => $attempt->final_grade === null ? null : (float) $attempt->final_grade,
            ]),
            'Daftar percobaan kuis berhasil diambil',
        );
    }

    /** Jawaban detail sebuah percobaan (guru) — termasuk kunci (§26). */
    public function attemptDetail(Request $request, LmsQuizAttempt $attempt): JsonResponse
    {
        $activity = $attempt->quiz?->activity;
        $this->authorizeGrade($request, $activity);

        $attempt->loadMissing(['questions', 'answers']);

        return $this->successResponse([
            'attempt' => $this->attempts->summary($attempt),
            'questions' => $attempt->questions->map(function ($item) use ($attempt) {
                $answer = $attempt->answers->firstWhere('question_id', $item->question_id);
                $snapshot = (array) $item->snapshot;

                return [
                    'question_id' => (int) $item->question_id,
                    'question_text' => $snapshot['question_text'] ?? null,
                    'question_type' => $snapshot['question_type'] ?? null,
                    'marks' => (float) $item->marks,
                    'options' => array_map(fn (array $option) => [
                        'id' => (int) $option['id'],
                        'text' => $option['text'] ?? '',
                        'is_correct' => (bool) ($option['is_correct'] ?? false),
                    ], (array) ($snapshot['options'] ?? [])),
                    'answer' => $answer === null ? null : [
                        'id' => (int) $answer->id,
                        'answer' => $answer->answer,
                        'answer_data' => $answer->answer_data,
                        'marks' => $answer->marks === null ? null : (float) $answer->marks,
                        'is_correct' => $answer->is_correct,
                        'feedback' => $answer->feedback,
                        'graded_at' => $answer->graded_at?->toIso8601String(),
                    ],
                ];
            })->all(),
        ], 'Detail percobaan berhasil diambil');
    }

    // ------------------------------------------------------------------ alias §53

    /** GET /lms/quizzes/{quiz} — tampilan kuis untuk siswa. */
    public function showByQuiz(Request $request, LmsQuiz $quiz): JsonResponse
    {
        return $this->show($request, (int) $quiz->activity_id);
    }

    /** POST /lms/quizzes/{quiz}/attempts — mulai percobaan. */
    public function startAttempt(Request $request, LmsQuiz $quiz): JsonResponse
    {
        return app(LmsQuizAttemptController::class)->store($request, (int) $quiz->activity_id);
    }

    /** GET /lms/quizzes/{quiz}/statistics — statistik kuis. */
    public function statisticsByQuiz(Request $request, LmsQuiz $quiz): JsonResponse
    {
        return $this->statistics($request, (int) $quiz->activity_id);
    }
}
