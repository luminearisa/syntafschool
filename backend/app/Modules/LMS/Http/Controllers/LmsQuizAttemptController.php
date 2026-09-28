<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Application\Services\ActivityAvailabilityService;
use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\QuizAttemptService;
use App\Modules\LMS\Application\Services\QuizService;
use App\Modules\LMS\Application\Services\QuizGradingService;
use App\Modules\LMS\Http\Controllers\Concerns\ResolvesActivities;
use App\Modules\LMS\Models\LmsQuizAnswer;
use App\Modules\LMS\Models\LmsQuizAttempt as QuizAttempt;
use App\Modules\LMS\Models\LmsQuizAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pengerjaan kuis: mulai, jawab, kirim, hasil, dan penilaian esai
 * (§25–§29, §53–§54).
 */
class LmsQuizAttemptController extends LmsController
{
    use ResolvesActivities;

    public function __construct(
        private readonly QuizAttemptService $attempts,
        private readonly QuizService $quizzes,
        private readonly QuizGradingService $grading,
        private readonly ActivityAvailabilityService $availability,
        private readonly CourseAccessService $access,
    ) {}

    /** Mulai / lanjutkan percobaan (§25, §27). */
    public function store(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        [$actor, $enrollment] = $this->requireEnrollment($request, $model);

        $state = $this->availability->studentState($model, $enrollment);
        $this->authorizeLms(! $state['locked'], $state['reason_label'] ?? 'Aktivitas terkunci.');

        $data = $this->validatePayload($request, ['password' => ['nullable', 'string']]);

        $attempt = $this->attempts->start($model, $enrollment, $data, $actor->studentId);

        return $this->createdResponse(
            $this->attempts->payload($attempt),
            'Percobaan kuis dimulai. Waktu dihitung oleh server.',
        );
    }

    /** Payload pengerjaan / hasil percobaan (§26, §29). */
    public function show(Request $request, LmsQuizAttempt $attempt): JsonResponse
    {
        $actor = $this->actor($request);
        $activity = $attempt->quiz?->activity;
        $course = $activity?->course;

        $isStaff = $this->access->canGrade($course, $actor);

        $this->authorizeLms((int) $attempt->user_id === $actor->id || $isStaff, 'Anda tidak berhak melihat percobaan ini.');

        // Auto submit bila waktu habis — server sebagai sumber kebenaran (§27).
        $this->attempts->expireIfNeeded($attempt);

        return $this->successResponse(
            $this->attempts->payload($attempt->fresh(['questions', 'answers']), includeReview: true),
            'Percobaan kuis berhasil diambil',
        );
    }

    /** Simpan jawaban satu soal (§26). */
    public function saveAnswer(Request $request, LmsQuizAttempt $attempt): JsonResponse
    {
        $actor = $this->actor($request);

        $this->authorizeLms((int) $attempt->user_id === $actor->id, 'Anda hanya dapat menjawab percobaan sendiri.');

        $data = $this->validatePayload($request, [
            'question_id' => ['required', 'integer'],
            'answer' => ['nullable', 'string'],
            'text' => ['nullable', 'string'],
            'answer_data' => ['nullable', 'array'],
        ]);

        $answer = $this->attempts->saveAnswer($attempt, (int) $data['question_id'], $data);

        return $this->successResponse([
            'question_id' => (int) $answer->question_id,
            'saved_at' => $answer->updated_at?->toIso8601String(),
            'seconds_remaining' => $attempt->fresh()->secondsRemaining(),
        ], 'Jawaban tersimpan');
    }

    /** Kirim percobaan → penilaian otomatis (§25, §27). */
    public function submit(Request $request, LmsQuizAttempt $attempt): JsonResponse
    {
        $actor = $this->actor($request);

        $this->authorizeLms((int) $attempt->user_id === $actor->id, 'Anda hanya dapat mengirim percobaan sendiri.');

        $result = $this->attempts->submit($attempt, $actor);

        return $this->successResponse(
            $this->attempts->payload($result, includeReview: true),
            $result->needs_manual_grading
                ? 'Jawaban terkirim. Soal esai akan dinilai guru.'
                : 'Kuis selesai dan sudah dinilai otomatis.',
        );
    }

    /** Hasil percobaan (mengikuti review options kuis, §29). */
    public function result(Request $request, LmsQuizAttempt $attempt): JsonResponse
    {
        $actor = $this->actor($request);
        $activity = $attempt->quiz?->activity;
        $isStaff = $this->access->canGrade($activity?->course, $actor);

        $this->authorizeLms((int) $attempt->user_id === $actor->id || $isStaff, 'Anda tidak berhak melihat hasil ini.');

        return $this->successResponse(
            $this->attempts->payload($attempt, includeReview: true) + [
                'aggregate' => $this->grading->aggregate($attempt),
            ],
            'Hasil kuis berhasil diambil',
        );
    }

    /** Penilaian manual jawaban esai (§20). */
    public function gradeAnswer(Request $request, LmsQuizAnswer $answer): JsonResponse
    {
        $activity = $answer->attempt?->quiz?->activity;
        $actor = $this->authorizeGrade($request, $activity);

        $data = $this->validatePayload($request, [
            'marks' => ['required', 'numeric', 'min:0'],
            'feedback' => ['nullable', 'string'],
        ]);

        $graded = $this->grading->gradeAnswer($answer, (float) $data['marks'], $data['feedback'] ?? null, $actor);

        return $this->successResponse([
            'answer_id' => (int) $graded->id,
            'marks' => (float) $graded->marks,
            'is_correct' => $graded->is_correct,
            'feedback' => $graded->feedback,
            'attempt' => $graded->attempt === null ? null : $this->attempts->summary($graded->attempt->fresh()),
        ], 'Penilaian jawaban tersimpan');
    }

    /** Percobaan milik seorang siswa pada aktivitas kuis (guru/wali, §48). */
    public function forStudent(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $actor = $this->actor($request);
        // Bisa dari segmen rute (`student-attempts/{user_id}`) atau kueri.
        $userId = (int) ($request->route('user_id') ?? $request->query('user_id', $actor->id));

        $isSelf = $userId === $actor->id;
        $isStaff = $this->access->canGrade($model->course, $actor);
        $isParent = $actor->isParent() && $this->access->parentWatchesCourse($model->course, $actor);

        $this->authorizeLms($isSelf || $isStaff || $isParent, 'Anda tidak berhak melihat percobaan ini.');

        return $this->successResponse(
            $this->attempts->forStudent($this->quizzes->ensureConfig($model), $userId),
            'Daftar percobaan berhasil diambil',
        );
    }
}
