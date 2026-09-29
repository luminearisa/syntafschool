<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\GradebookService;
use App\Modules\LMS\Application\Services\GradeReviewService;
use App\Modules\LMS\Application\Services\GradeStatisticsService;
use App\Modules\LMS\Domain\Contracts\StudentProviderInterface;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsGradeItem;
use App\Modules\LMS\Models\LmsGradeReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Banding/review nilai (§20, §59).
 */
class LmsGradeReviewController extends LmsController
{
    public function __construct(
        private readonly GradeReviewService $reviews,
        private readonly CourseAccessService $access,
        private readonly GradebookService $gradebooks,
        private readonly GradeStatisticsService $statistics,
    ) {}

    /** Siswa mengajukan banding (§59 siswa). */
    public function store(Request $request, LmsGradeItem $item): JsonResponse
    {
        $actor = $this->actor($request);

        $data = $this->validatePayload($request, [
            'reason' => ['required', 'string', 'max:2000'],
            'requested_score' => ['nullable', 'numeric', 'min:0'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $target = (int) ($data['user_id'] ?? $actor->id);

        $review = $this->reviews->request(
            $item,
            $target,
            $data['reason'],
            $actor,
            isset($data['requested_score']) ? (float) $data['requested_score'] : null,
        );

        return $this->createdResponse($this->reviews->payload($review), 'Pengajuan banding nilai terkirim');
    }

    /** Daftar banding: siswa → miliknya; guru → antrean course-nya. */
    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        if ($request->query('course_id') !== null || $actor->can('lms.grade.review')) {
            $courseId = $request->query('course_id');
            $gradebookId = null;

            if ($courseId !== null) {
                $course = LmsCourse::query()->findOrFail((int) $courseId);
                $this->authorizeLms(
                    $this->access->canGrade($course, $actor) || $actor->can('lms.grade.review'),
                    'Anda tidak berhak melihat antrean banding course ini.',
                );
                $gradebookId = (int) $this->gradebooks->ensureForCourse($course, $actor->id)->id;
            }

            $itemId = $request->query('grade_item_id');

            return $this->successResponse(
                $this->reviews->queue(
                    $itemId === null ? null : LmsGradeItem::query()->findOrFail((int) $itemId),
                    $gradebookId,
                    $actor,
                ),
                'Antrean banding nilai berhasil diambil',
            );
        }

        return $this->successResponse($this->reviews->forStudent($actor->id), 'Daftar banding nilai berhasil diambil');
    }

    public function show(Request $request, LmsGradeReview $review): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeView($review, $actor);

        return $this->successResponse($this->reviews->payload($review), 'Detail banding nilai');
    }

    public function start(Request $request, LmsGradeReview $review): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeTeacher($review, $actor);

        return $this->successResponse(
            $this->reviews->payload($this->reviews->start($review, $actor)),
            'Banding mulai ditinjau',
        );
    }

    /** Setujui banding → nilai dikoreksi (§59 guru). */
    public function approve(Request $request, LmsGradeReview $review): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeTeacher($review, $actor);

        $data = $this->validatePayload($request, [
            'score' => ['required', 'numeric', 'min:0'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $result = $this->reviews->approve($review, (float) $data['score'], $data['note'], $actor);

        return $this->successResponse([
            'review' => $this->reviews->payload($result['review']),
            'previous' => $result['correction']['previous'],
            'corrected' => $result['correction']['corrected'],
        ], 'Banding disetujui dan nilai dikoreksi');
    }

    public function reject(Request $request, LmsGradeReview $review): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeTeacher($review, $actor);

        $data = $this->validatePayload($request, [
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->successResponse(
            $this->reviews->payload($this->reviews->reject($review, $data['note'], $actor)),
            'Banding ditolak',
        );
    }

    public function close(Request $request, LmsGradeReview $review): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeTeacher($review, $actor);

        $data = $this->validatePayload($request, ['note' => ['nullable', 'string', 'max:2000']]);

        return $this->successResponse(
            $this->reviews->payload($this->reviews->close($review, $actor, $data['note'] ?? null)),
            'Banding ditutup',
        );
    }

    /** Catatan tambahan (siswa atau guru). */
    public function comment(Request $request, LmsGradeReview $review): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeView($review, $actor);

        $data = $this->validatePayload($request, ['note' => ['required', 'string', 'max:2000']]);

        $this->reviews->comment($review, $data['note'], $actor);

        return $this->successResponse($this->reviews->payload($review->refresh()), 'Catatan tersimpan');
    }

    private function authorizeView(LmsGradeReview $review, LmsActor $actor): void
    {
        $isOwner = (int) $review->user_id === $actor->id;
        $isParent = $actor->isParent() && $review->is_parent_visible && $this->parentWatches($review, $actor);
        $isTeacher = $this->isCourseTeacher($review, $actor);

        $this->authorizeLms($isOwner || $isParent || $isTeacher, 'Anda tidak berhak melihat banding nilai ini.');
    }

    private function authorizeTeacher(LmsGradeReview $review, LmsActor $actor): void
    {
        $this->authorizeLms(
            $this->isCourseTeacher($review, $actor) && ($actor->can('lms.grade.review') || $actor->can('lms.grade.approve')),
            'Anda tidak berhak memutuskan banding nilai ini.',
        );
    }

    private function isCourseTeacher(LmsGradeReview $review, LmsActor $actor): bool
    {
        $courseId = $review->gradebook()->first()?->course_id;

        if ($courseId === null) {
            return false;
        }

        $course = LmsCourse::query()->find((int) $courseId);

        return $course !== null && $this->canAccessCourseGrades($actor, $course);
    }

    private function parentWatches(LmsGradeReview $review, LmsActor $actor): bool
    {
        foreach (app(StudentProviderInterface::class)->childrenOf($actor) as $child) {
            if ((int) ($child->userId ?? 0) === (int) $review->user_id) {
                return true;
            }
        }

        return false;
    }
}
