<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Models\Concerns\ResolvesIdentity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Jejak halaman lesson yang sudah dilewati peserta. */
class LmsLessonProgress extends BaseModel
{
    use ResolvesIdentity;

    protected $table = 'lms_lesson_progress';

    protected $casts = [
        'visited_pages' => 'array',
        'attempts' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(LmsLesson::class, 'lesson_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'user_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }

    /** @return list<int> */
    public function visitedPageIds(): array
    {
        return array_map('intval', $this->visited_pages ?? []);
    }

    public function markVisited(int $pageId): self
    {
        $visited = $this->visitedPageIds();

        if (! in_array($pageId, $visited, true)) {
            $visited[] = $pageId;
            $this->visited_pages = $visited;
        }

        $this->last_page_id = $pageId;
        $this->save();

        return $this;
    }
}
