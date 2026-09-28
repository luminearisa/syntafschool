<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\CourseAnnouncementService;
use App\Modules\LMS\Application\Services\CourseCompletionService;
use App\Modules\LMS\Application\Services\CourseContentService;
use App\Modules\LMS\Application\Services\CourseProgressService;
use App\Modules\LMS\Domain\Enums\CompletionType;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Http\Resources\LmsAnnouncementResource;
use App\Modules\LMS\Http\Resources\LmsLearningItemResource;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsLearningItem;
use App\Modules\LMS\Models\LmsLearningView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pengalaman belajar (§39): player course, isi materi, Previous/Next,
 * Mark as Complete, dan pelacakan "baru dibuka".
 */
class LmsLearningController extends LmsController
{
    public function __construct(
        private readonly CourseAccessService $access,
        private readonly CourseContentService $content,
        private readonly CourseProgressService $progress,
        private readonly CourseCompletionService $completions,
        private readonly CourseAnnouncementService $announcements,
    ) {}

    /** GET /lms/courses/{course}/learn — kerangka player. */
    public function player(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $decision = $this->access->decide($course, $actor);
        $this->authorizeLms($decision->allowed, $decision->message ?? 'Anda tidak memiliki akses ke course ini.');

        $enrollment = $course->enrollmentFor($actor->id);
        $progress = $this->progress->summary($course, $actor);

        return $this->successResponse([
            'course' => [
                'id' => (int) $course->id,
                'name' => (string) $course->name,
                'code' => (string) $course->code,
                'slug' => (string) $course->slug,
                'thumbnail' => $course->thumbnail,
                'cover_image' => $course->cover_image,
                'level' => $course->level?->value,
                'status' => $course->status?->value,
                'start_date' => $course->start_date?->format('Y-m-d'),
                'end_date' => $course->end_date?->format('Y-m-d'),
                'teachers' => $course->teachers()->pluck('name')->filter()->values()->all(),
            ],
            'structure' => $this->content->structure($course, $actor, $enrollment, $this->access),
            'progress' => $progress,
            'resume' => [
                'next_item_id' => $progress['next_item_id'] ?? null,
                'last_item_id' => $progress['last_item_id'] ?? null,
            ],
            'announcements' => LmsAnnouncementResource::collection(
                $this->announcements->forCourse($course)->limit(5)->get(),
            ),
            'capabilities' => [
                'manage' => $this->access->canManage($course, $actor),
                'read_only' => $actor->isParent(),
            ],
        ], 'Kerangka belajar berhasil diambil');
    }

    /** GET /lms/courses/{course}/items/{item} — isi satu materi. */
    public function item(Request $request, LmsCourse $course, LmsLearningItem $item): JsonResponse
    {
        $actor = $this->actor($request);

        $this->assertItemBelongsToCourse($course, $item);

        $decision = $this->access->decide($course, $actor, $item);
        $this->authorizeLms($decision->allowed, $decision->message ?? 'Materi ini terkunci.');

        $item->load(['resource', 'chapters', 'children']);

        $enrollment = $course->enrollmentFor($actor->id);
        $completed = $enrollment !== null && $this->completions->isComplete($enrollment, (int) $item->id);

        return $this->successResponse([
            'item' => new LmsLearningItemResource($item),
            'access' => $decision->toArray(),
            'completed' => $completed,
            'completion' => [
                'type' => $item->completion_type?->value,
                'can_mark_complete' => $this->canMarkComplete($item, $actor),
            ],
            'navigation' => [
                'previous' => $this->content->navigation($item, 'previous'),
                'next' => $this->content->navigation($item, 'next'),
            ],
            'progress' => $enrollment !== null ? $this->progress->summary($course, $actor) : null,
        ], 'Isi materi berhasil diambil');
    }

    /**
     * POST /lms/courses/{course}/items/{item}/view — catat materi dibuka.
     *
     * Item dengan `completion_type = VIEW` langsung tuntas di sini; tipe lain
     * menunggu aksi eksplisit (tandai selesai, kumpulkan, lulus kuis).
     */
    public function trackView(Request $request, LmsCourse $course, LmsLearningItem $item): JsonResponse
    {
        $actor = $this->actor($request);
        $this->assertItemBelongsToCourse($course, $item);

        $decision = $this->access->decide($course, $actor, $item);
        $this->authorizeLms($decision->allowed, $decision->message ?? 'Materi ini terkunci.');

        LmsLearningView::create([
            'user_id' => $actor->id,
            'course_id' => $course->id,
            'learning_item_id' => $item->id,
            'viewed_at' => now(),
        ]);

        $enrollment = $course->enrollmentFor($actor->id);
        $autoCompleted = false;

        if ($enrollment !== null && $enrollment->isActive()) {
            $this->progress->touchLastItem($enrollment, $item);

            if (($item->completion_type ?? CompletionType::NONE) === CompletionType::VIEW && ! $actor->isParent()) {
                $this->completions->markComplete($enrollment, $item, null, 'view', $actor->id);
                $autoCompleted = true;
            }

            // Wajib dihitung ulang: penyelesaian baru saja bertambah, dan baris
            // progress tidak boleh dibiarkan basi (dipakai dashboard & progress bar).
            $progress = $this->progress->recompute($enrollment);

            return $this->successResponse([
                'auto_completed' => $autoCompleted,
                'progress' => $this->progress->summary($course, $actor),
                'next_item_id' => $progress->next_item_id !== null ? (int) $progress->next_item_id : null,
            ], 'Aktivitas belajar tercatat');
        }

        return $this->successResponse(['auto_completed' => false, 'progress' => null], 'Aktivitas belajar tercatat');
    }

    protected function canMarkComplete(LmsLearningItem $item, $actor): bool
    {
        if ($actor->isParent()) {
            return false;
        }

        return ($item->completion_type ?? CompletionType::NONE) !== CompletionType::NONE
            && $this->access->canManage($item->course, $actor) === false;
    }

    protected function assertItemBelongsToCourse(LmsCourse $course, LmsLearningItem $item): void
    {
        if ((int) $item->course_id !== (int) $course->id) {
            abort(404, 'Materi tidak ditemukan pada course ini.');
        }
    }
}
