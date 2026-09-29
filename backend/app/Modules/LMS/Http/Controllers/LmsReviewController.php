<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\CourseReviewService;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ulasan & umpan balik course (§31–§32).
 *
 * Ulasan siswa lain ditampilkan tanpa identitas lengkap bila sekolah memilih
 * mode privasi (§32); moderasi hanya boleh dilakukan pengelola course.
 */
class LmsReviewController extends LmsController
{
    public function __construct(
        private readonly CourseReviewService $reviews,
        private readonly CourseAccessService $access,
    ) {}

    /** GET /lms/courses/{course}/reviews */
    public function index(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);

        $this->authorizeLms(
            $model->isPublished() || $this->access->canManage($model, $actor) || $model->enrollmentFor($actor->id) !== null,
            'Course ini belum menerbitkan ulasan.',
        );

        return $this->successResponse([
            'enabled' => $this->reviews->enabled($model),
            'summary' => $this->reviews->summary($model),
            'reviews' => $this->reviews->publicFor($model, (int) $this->perPage($request, 10)),
            'mine' => $this->reviews->forUser($model, (int) $actor->id),
        ]);
    }

    /** POST /lms/courses/{course}/reviews */
    public function store(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:180'],
            'comment' => ['nullable', 'string', 'max:4000'],
        ]);

        return $this->createdResponse($this->reviews->submit($model, $actor, $data), 'Ulasan terkirim.');
    }

    /** PUT /lms/reviews/{review} */
    public function update(Request $request, int $review): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->review($review);

        $data = $request->validate([
            'rating' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:180'],
            'comment' => ['nullable', 'string', 'max:4000'],
        ]);

        return $this->successResponse($this->reviews->update($model, $data, $actor), 'Ulasan diperbarui.');
    }

    /** DELETE /lms/reviews/{review} */
    public function destroy(Request $request, int $review): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->review($review);

        $this->reviews->delete($model, $actor);

        return $this->successResponse(null, 'Ulasan dihapus.');
    }

    /** POST /lms/reviews/{review}/helpful */
    public function helpful(Request $request, int $review): JsonResponse
    {
        $this->actor($request);

        return $this->successResponse($this->reviews->markHelpful($this->review($review)), 'Terima kasih atas penilaiannya.');
    }

    /** GET /lms/courses/{course}/reviews/moderation */
    public function moderation(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);
        $this->authorizeModerate($model);

        return $this->successResponse([
            'reviews' => $this->reviews->forModeration($model, $request->only('status', 'rating', 'search')),
            'summary' => $this->reviews->summary($model),
            'feedback' => $this->reviews->feedbackSummary($model),
        ]);
    }

    /** POST /lms/reviews/{review}/moderate */
    public function moderate(Request $request, int $review): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->review($review);
        $this->authorizeModerate($model->course);

        $data = $request->validate([
            'decision' => ['required', 'string', 'in:APPROVED,REJECTED,PENDING,FLAGGED'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->successResponse(
            $this->reviews->moderate($model, $actor, (string) $data['decision'], $data['note'] ?? null),
            'Moderasi tersimpan.',
        );
    }

    /** POST /lms/courses/{course}/feedback */
    public function submitFeedback(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);

        $data = $request->validate([
            'liked' => ['nullable', 'string', 'max:2000'],
            'improve' => ['nullable', 'string', 'max:2000'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        return $this->createdResponse($this->reviews->submitFeedback($model, $actor, $data), 'Umpan balik terkirim.');
    }

    /** GET /lms/courses/{course}/feedback */
    public function feedback(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);
        $this->authorizeModerate($model);

        return $this->successResponse([
            'summary' => $this->reviews->feedbackSummary($model),
            'items' => $this->reviews->feedbackForCourse($model, $request->query('status')),
        ]);
    }

    /** POST /lms/courses/{course}/reviews/refresh-rating */
    public function refreshRating(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);
        $this->authorizeLms($actor->can('lms.manage'), 'Hanya admin yang dapat menyegarkan cache rating.');

        return $this->successResponse($this->reviews->refreshRatingCache($model), 'Cache rating diperbarui.');
    }

    private function course(int $id): LmsCourse
    {
        $course = LmsCourse::query()->find($id);
        abort_if($course === null, 404, 'Course tidak ditemukan.');

        return $course;
    }

    private function review(int $id): LmsCourseReview
    {
        $review = LmsCourseReview::query()->with('course')->find($id);
        abort_if($review === null, 404, 'Ulasan tidak ditemukan.');

        return $review;
    }

    private function authorizeModerate(LmsCourse $course): void
    {
        $actor = $this->actor(request());

        $this->authorizeLms(
            ($actor->can('lms.review.moderate') || $actor->can('lms.manage'))
                && ($this->access->canManage($course, $actor) || $actor->can('lms.report.view')),
            'Anda tidak berhak memoderasi ulasan course ini.',
        );
    }
}
