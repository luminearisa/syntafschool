<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsBookmark;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsLearningItem;
use Illuminate\Pagination\LengthAwarePaginator;

/** Bookmark course & materi (§32). */
class LmsBookmarkService
{
    /** @return array{bookmarked: bool, id: int|null} */
    public function toggle(LmsActor $actor, LmsCourse $course, ?LmsLearningItem $item = null, ?string $note = null): array
    {
        $existing = LmsBookmark::query()
            ->where('user_id', $actor->id)
            ->where('course_id', $course->id)
            ->where('learning_item_id', $item?->id)
            ->first();

        if ($existing !== null) {
            $existing->delete();

            return ['bookmarked' => false, 'id' => null];
        }

        $bookmark = LmsBookmark::create([
            'user_id' => $actor->id,
            'course_id' => $course->id,
            'learning_item_id' => $item?->id,
            'note' => $note,
        ]);

        return ['bookmarked' => true, 'id' => (int) $bookmark->id];
    }

    public function delete(LmsBookmark $bookmark): void
    {
        $bookmark->delete();
    }

    public function isBookmarked(LmsActor $actor, LmsCourse $course, ?LmsLearningItem $item = null): bool
    {
        return LmsBookmark::query()
            ->where('user_id', $actor->id)
            ->where('course_id', $course->id)
            ->where('learning_item_id', $item?->id)
            ->exists();
    }

    public function paginate(LmsActor $actor, int $perPage = 15): LengthAwarePaginator
    {
        return LmsBookmark::query()
            ->with([
                'course:id,name,slug,code,thumbnail,status,visibility,level',
                'item:id,title,resource_type,activity_type,item_type,course_id,section_id',
                'item.section:id,title',
            ])
            ->where('user_id', $actor->id)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
