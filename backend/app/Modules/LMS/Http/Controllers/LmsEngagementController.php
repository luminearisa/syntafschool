<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\GamificationService;
use App\Modules\LMS\Application\Services\LearningHealthService;
use App\Modules\LMS\Application\Services\LmsMessagingService;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kesehatan belajar, keterlibatan, dan pesan (§33–§35, §58–§61).
 *
 * Label kesehatan belajar hanya untuk pemantauan operasional — bukan penilaian
 * kemampuan atau kondisi pribadi siswa.
 */
class LmsEngagementController extends LmsController
{
    public function __construct(
        private readonly LearningHealthService $health,
        private readonly GamificationService $gamification,
        private readonly CourseReviewService $reviews,
        private readonly LmsMessagingService $messaging,
        private readonly CourseAccessService $access,
    ) {}

    /** GET /lms/courses/{course}/health */
    public function courseHealth(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);
        $this->authorizeHealth($model);

        return $this->successResponse([
            'thresholds' => $this->health->thresholds(),
            'statuses' => $this->health->statuses(),
            'summary' => $this->health->summaryForCourse($model, (int) $this->perPage($request, 10)),
            'trend' => $this->health->trendForCourse($model, $request->filled('days') ? (int) $request->query('days') : null),
            'dashboard' => $this->health->dashboardForTeacher($model),
        ]);
    }

    /** GET /lms/courses/{course}/engagement */
    public function courseEngagement(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);
        $this->authorizeHealth($model);

        return $this->successResponse([
            'health' => $this->health->summaryForCourse($model, 10),
            'leaderboard' => $this->gamification->leaderboardEnabled($model)
                ? $this->gamification->leaderboardForCourse($model, 10, (int) $actor->id)
                : [],
            'reviews' => $this->reviews->summary($model),
            'feedback' => $this->reviews->feedbackSummary($model),
            'trend' => $this->health->trendForCourse($model),
        ]);
    }

    /** GET /lms/my/learning-health */
    public function myHealth(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        return $this->successResponse([
            'at_risk' => $this->health->atRiskAcrossCourses((int) $actor->id),
            'recent_activity' => $this->health->recentActivity((int) $actor->id, null, 20),
            'streak' => $this->gamification->streakFor((int) $actor->id),
        ]);
    }

    /** GET /lms/students/{enrollment}/health */
    public function studentHealth(Request $request, int $enrollment): JsonResponse
    {
        $actor = $this->actor($request);
        $model = LmsEnrollment::query()->with('course')->find($enrollment);
        abort_if($model === null, 404, 'Pendaftaran tidak ditemukan.');

        $this->authorizeLms(
            $this->access->canManage($model->course, $actor)
            || (int) $model->user_id === $actor->id
            || $this->access->parentWatchesCourse($model->course, $actor)
            || $actor->can('lms.engagement.view'),
            'Anda tidak berhak melihat data belajar ini.',
        );

        return $this->successResponse([
            'status' => $this->health->statusFor($model)->value,
            'metrics' => $this->health->metricsFor($model),
            'recent_activity' => $this->health->recentActivity((int) $model->user_id, (int) $model->course_id, 15),
            'streak' => $this->gamification->streakFor((int) $model->user_id, (int) $model->course_id),
        ]);
    }

    /** POST /lms/courses/{course}/messages */
    public function sendMessage(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);
        $this->authorizeHealth($model);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer'],
            'broadcast' => ['nullable', 'boolean'],
            'student_id' => ['nullable', 'integer'],
            'to_guardians' => ['nullable', 'boolean'],
        ]);

        if (($data['to_guardians'] ?? false) === true && isset($data['student_id'])) {
            return $this->successResponse(
                $this->messaging->sendToGuardians($model, $actor, (int) $data['student_id'], (string) $data['message']),
                'Pesan dikirim ke wali murid.',
            );
        }

        if (($data['broadcast'] ?? false) === true) {
            return $this->successResponse(
                $this->messaging->broadcastToCourse($model, $actor, (string) $data['message']),
                'Pesan disiarkan ke peserta.',
            );
        }

        return $this->successResponse(
            $this->messaging->sendToStudents($model, $actor, (array) ($data['user_ids'] ?? []), (string) $data['message']),
            'Pesan dikirim.',
        );
    }

    /** GET /lms/courses/{course}/messages/recipients */
    public function recipients(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);
        $this->authorizeHealth($model);

        $rows = LmsEnrollment::query()
            ->where('course_id', $model->id)
            ->where('role', 'STUDENT')
            ->orderBy('user_id')
            ->limit(500)
            ->get(['user_id', 'student_id', 'status'])
            ->map(fn (LmsEnrollment $enrollment) => [
                'user_id' => (int) $enrollment->user_id,
                'student_id' => $enrollment->student_id !== null ? (int) $enrollment->student_id : null,
                'status' => $enrollment->status?->value,
                'guardians' => $enrollment->student_id !== null
                    ? $this->messaging->guardianUserIdsFor((int) $enrollment->student_id)
                    : [],
            ])
            ->all();

        return $this->successResponse([
            'enabled' => $this->messaging->enabled(),
            'recipients' => $rows,
        ]);
    }

    private function course(int $id): LmsCourse
    {
        $course = LmsCourse::query()->find($id);
        abort_if($course === null, 404, 'Course tidak ditemukan.');

        return $course;
    }

    private function authorizeHealth(LmsCourse $course): void
    {
        $actor = $this->actor(request());

        $this->authorizeLms(
            ($this->access->canManage($course, $actor) && ($actor->can('lms.engagement.view') || $actor->can('lms.manage')))
            || $actor->can('lms.report.view'),
            'Anda tidak berhak melihat pemantauan course ini.',
        );
    }
}
