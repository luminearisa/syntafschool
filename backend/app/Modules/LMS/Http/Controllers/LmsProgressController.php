<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\CourseCompletionService;
use App\Modules\LMS\Application\Services\CourseProgressService;
use App\Modules\LMS\Domain\Enums\CompletionType;
use App\Modules\LMS\Domain\Enums\EnrollmentRole;
use App\Modules\LMS\Domain\Enums\EnrollmentStatus;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsLearningItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Progress & penyelesaian: /lms/my/*, mark complete, continue learning (§22, §23, §37). */
class LmsProgressController extends LmsController
{
    public function __construct(
        private readonly CourseProgressService $progress,
        private readonly CourseCompletionService $completions,
        private readonly CourseAccessService $access,
    ) {}

    /** GET /lms/my/courses */
    public function myCourses(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        $enrollments = LmsEnrollment::query()
            ->with(['course:id,name,code,slug,thumbnail,cover_image,status,visibility,level,short_description', 'progress'])
            ->where('user_id', $actor->id)
            ->where('role', EnrollmentRole::STUDENT->value)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('last_accessed_at')
            ->get();

        return $this->successResponse([
            'summary' => [
                'total' => $enrollments->count(),
                'active' => $enrollments->where('status', EnrollmentStatus::ACTIVE)->count(),
                'completed' => $enrollments->where('status', EnrollmentStatus::COMPLETED)->count(),
                'average_progress' => $enrollments->isEmpty() ? 0 : round((float) $enrollments->avg('progress_percent'), 2),
            ],
            'courses' => $enrollments->map(fn (LmsEnrollment $enrollment) => [
                'enrollment_id' => (int) $enrollment->id,
                'status' => $enrollment->status?->value,
                'progress_percent' => (float) ($enrollment->progress->progress_percent ?? $enrollment->progress_percent),
                'completed_items' => (int) ($enrollment->progress->completed_items ?? 0),
                'required_items' => (int) ($enrollment->progress->required_items ?? 0),
                'next_item_id' => $enrollment->progress?->next_item_id !== null ? (int) $enrollment->progress->next_item_id : null,
                'last_accessed_at' => $enrollment->last_accessed_at?->toIso8601String(),
                'course' => [
                    'id' => (int) $enrollment->course_id,
                    'name' => $enrollment->course?->name,
                    'code' => $enrollment->course?->code,
                    'slug' => $enrollment->course?->slug,
                    'thumbnail' => $enrollment->course?->thumbnail,
                    'cover_image' => $enrollment->course?->cover_image,
                    'level' => $enrollment->course?->level?->value,
                    'status' => $enrollment->course?->status?->value,
                ],
            ])->values()->all(),
        ], 'Daftar course saya berhasil diambil');
    }

    /** GET /lms/my/progress */
    public function myProgress(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        $enrollments = LmsEnrollment::query()
            ->with('progress')
            ->where('user_id', $actor->id)
            ->where('role', EnrollmentRole::STUDENT->value)
            ->get();

        return $this->successResponse([
            'courses' => $enrollments->map(fn (LmsEnrollment $enrollment) => [
                'course_id' => (int) $enrollment->course_id,
                'enrollment_id' => (int) $enrollment->id,
                'status' => $enrollment->status?->value,
                'progress_percent' => (float) ($enrollment->progress->progress_percent ?? $enrollment->progress_percent),
                'completed_items' => (int) ($enrollment->progress->completed_items ?? 0),
                'required_items' => (int) ($enrollment->progress->required_items ?? 0),
                'completed_at' => $enrollment->completed_at?->toIso8601String(),
            ])->values()->all(),
            'totals' => [
                'courses' => $enrollments->count(),
                'completed_courses' => $enrollments->where('status', EnrollmentStatus::COMPLETED)->count(),
                'completed_items' => (int) $enrollments->sum(
                    fn (LmsEnrollment $enrollment) => (int) ($enrollment->progress->completed_items ?? 0),
                ),
                'average_progress' => $enrollments->isEmpty() ? 0 : round((float) $enrollments->avg('progress_percent'), 2),
            ],
        ], 'Ringkasan progress saya berhasil diambil');
    }

    /** GET /lms/courses/{course}/progress */
    public function courseProgress(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $decision = $this->access->decide($course, $actor);

        $this->authorizeLms($decision->allowed || $this->access->canManage($course, $actor));

        return $this->successResponse(
            $this->progress->summary($course, $actor),
            'Progress course berhasil diambil',
        );
    }

    /** POST /lms/courses/{course}/items/{item}/complete — "Mark as Complete". */
    public function complete(Request $request, LmsCourse $course, LmsLearningItem $item): JsonResponse
    {
        $actor = $this->actor($request);

        if ((int) $item->course_id !== (int) $course->id) {
            abort(404, 'Materi tidak ditemukan pada course ini.');
        }

        if ($actor->isParent()) {
            return $this->errorResponse('Orang tua/wali tidak dapat menandai penyelesaian atas nama anak.', 403);
        }

        $decision = $this->access->decide($course, $actor, $item);
        $this->authorizeLms($decision->allowed, $decision->message ?? 'Materi ini terkunci.');

        $enrollment = $course->enrollmentFor($actor->id);

        if ($enrollment === null || ! $enrollment->isActive()) {
            return $this->errorResponse('Anda tidak memiliki pendaftaran aktif pada course ini.', 403);
        }

        if (($item->completion_type ?? CompletionType::NONE) === CompletionType::NONE) {
            return $this->errorResponse('Materi ini tidak dihitung dalam progress course.', 422);
        }

        $data = $request->validate(['grade' => ['nullable', 'numeric', 'min:0', 'max:100']]);

        $result = $this->progress->complete(
            $enrollment,
            $item,
            isset($data['grade']) ? (float) $data['grade'] : null,
            'manual',
            $actor->id,
        );

        return $this->successResponse([
            'progress' => $this->progress->summary($course, $actor),
            'next' => $result['next'] !== null ? [
                'id' => (int) $result['next']->id,
                'title' => (string) $result['next']->title,
            ] : null,
        ], 'Materi ditandai selesai');
    }

    /** DELETE /lms/courses/{course}/items/{item}/complete */
    public function uncomplete(Request $request, LmsCourse $course, LmsLearningItem $item): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms(! $actor->isParent(), 'Orang tua/wali tidak dapat mengubah penyelesaian anak.');

        $enrollment = $course->enrollmentFor($actor->id);

        if ($enrollment === null) {
            return $this->errorResponse('Anda belum terdaftar pada course ini.', 403);
        }

        $this->completions->unmark($enrollment, (int) $item->id);
        $this->progress->recompute($enrollment);

        return $this->successResponse(
            $this->progress->summary($course, $actor),
            'Tanda selesai dibatalkan',
        );
    }

    /** GET /lms/courses/{course}/continue — lanjutkan belajar (§23). */
    public function continueLearning(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $decision = $this->access->decide($course, $actor);
        $this->authorizeLms($decision->allowed, $decision->message ?? 'Anda tidak memiliki akses ke course ini.');

        $enrollment = $course->enrollmentFor($actor->id);
        $progress = $enrollment?->progress ?? ($enrollment !== null ? $this->progress->recompute($enrollment) : null);
        $next = $progress?->next_item_id !== null ? LmsLearningItem::query()->find($progress->next_item_id) : null;

        return $this->successResponse([
            'enrolled' => $enrollment !== null,
            'progress_percent' => (float) ($progress?->progress_percent ?? 0),
            'next_item' => $next !== null ? [
                'id' => (int) $next->id,
                'title' => (string) $next->title,
                'section_id' => (int) $next->section_id,
                'resource_type' => $next->resource_type?->value,
                'activity_type' => $next->activity_type?->value,
            ] : null,
        ], 'Lanjutan belajar berhasil ditentukan');
    }
}
