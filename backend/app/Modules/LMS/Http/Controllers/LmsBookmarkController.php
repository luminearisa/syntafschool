<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\LmsBookmarkService;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Http\Resources\LmsBookmarkResource;
use App\Modules\LMS\Models\LmsBookmark;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsLearningItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Bookmark course & materi (§32). */
class LmsBookmarkController extends LmsController
{
    public function __construct(
        private readonly LmsBookmarkService $bookmarks,
        private readonly CourseAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(
            LmsBookmarkResource::collection($this->bookmarks->paginate($this->actor($request), $this->perPage($request))),
            'Daftar bookmark berhasil diambil',
        );
    }

    /** POST /lms/courses/{course}/bookmark — toggle. */
    public function toggle(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $decision = $this->access->decide($course, $actor);
        $this->authorizeLms($decision->allowed, $decision->message ?? 'Anda tidak memiliki akses ke course ini.');

        $data = $request->validate([
            'learning_item_id' => ['nullable', 'integer', 'exists:lms_learning_items,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $item = isset($data['learning_item_id'])
            ? LmsLearningItem::query()->where('course_id', $course->id)->find($data['learning_item_id'])
            : null;

        $result = $this->bookmarks->toggle($actor, $course, $item, $data['note'] ?? null);

        return $this->successResponse(
            $result + ['message' => $result['bookmarked'] ? 'Ditambahkan ke bookmark' : 'Bookmark dihapus'],
            $result['bookmarked'] ? 'Ditambahkan ke bookmark' : 'Bookmark dihapus',
        );
    }

    public function destroy(Request $request, LmsBookmark $bookmark): JsonResponse
    {
        $this->authorizeLms((int) $bookmark->user_id === $this->actor($request)->id, 'Bookmark ini bukan milik Anda.');

        $this->bookmarks->delete($bookmark);

        return $this->successResponse(null, 'Bookmark berhasil dihapus');
    }
}
