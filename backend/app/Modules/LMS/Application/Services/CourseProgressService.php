<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Enums\CompletionType;
use App\Modules\LMS\Domain\Enums\ContentVisibility;
use App\Modules\LMS\Domain\Enums\EnrollmentStatus;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseProgress;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsLearningItem;
use Illuminate\Support\Collection;

/**
 * Progress belajar (§23): Completed Items / Required Items × 100.
 *
 * Persentase SELALU dihitung ulang dari tabel penyelesaian, bukan dari jumlah
 * halaman yang dibuka, sehingga siswa dapat membatalkan centang dan angkanya
 * tetap jujur.
 */
class CourseProgressService
{
    public function __construct(private readonly CourseCompletionService $completions) {}

    public function recompute(LmsEnrollment $enrollment): LmsCourseProgress
    {
        $items = $this->countableItems((int) $enrollment->course_id);
        $required = $items->where('is_required', true)->values();
        $denominator = $required->isNotEmpty() ? $required : $items;

        $completedIds = $this->completions->completedItemIds($enrollment);
        $completed = $denominator->filter(fn (LmsLearningItem $item) => in_array((int) $item->id, $completedIds, true))->count();
        $total = $denominator->count();
        $percent = $total === 0 ? 0.0 : round($completed / $total * 100, 2);

        $progress = LmsCourseProgress::query()->firstOrNew(['enrollment_id' => $enrollment->id]);
        $next = $this->nextItem($enrollment, $completedIds);

        $progress->fill([
            'course_id' => $enrollment->course_id,
            'user_id' => $enrollment->user_id,
            'total_items' => $items->count(),
            'required_items' => $total,
            'completed_items' => $completed,
            'progress_percent' => $percent,
            'next_item_id' => $next?->id,
            'started_at' => $progress->started_at ?? ($completed > 0 ? now() : null),
            'completed_at' => $total > 0 && $completed >= $total ? ($progress->completed_at ?? now()) : null,
            'computed_at' => now(),
        ]);
        $progress->save();

        $enrollment->forceFill([
            'progress_percent' => $percent,
            'last_accessed_at' => now(),
            'status' => $this->nextStatus($enrollment, $progress),
            'completed_at' => $progress->completed_at,
        ])->save();

        return $progress;
    }

    /** Semua peserta sebuah course dihitung ulang (dipakai saat struktur berubah). */
    public function recomputeForCourse(LmsCourse $course): int
    {
        $count = 0;

        $course->enrollments()->where('role', 'STUDENT')->chunkById(100, function ($enrollments) use (&$count): void {
            foreach ($enrollments as $enrollment) {
                $this->recompute($enrollment);
                $count++;
            }
        });

        return $count;
    }

    public function for(LmsCourse $course, LmsActor $actor): ?LmsCourseProgress
    {
        $enrollment = $course->enrollmentFor($actor->id);

        return $enrollment?->progress;
    }

    /**
     * Tandai satu item tuntas lalu segarkan progress + kembalikan item berikutnya.
     *
     * @return array{progress: LmsCourseProgress, next: ?LmsLearningItem, item_completed: bool}
     */
    public function complete(
        LmsEnrollment $enrollment,
        LmsLearningItem $item,
        ?float $grade = null,
        string $source = 'manual',
        ?int $actorId = null,
    ): array {
        $this->completions->markComplete($enrollment, $item, $grade, $source, $actorId);
        $progress = $this->recompute($enrollment);

        return [
            'progress' => $progress,
            'next' => $progress->next_item_id !== null
                ? LmsLearningItem::query()->find($progress->next_item_id)
                : null,
            'item_completed' => true,
        ];
    }

    /** Item berikutnya yang belum tuntas dan sudah terbuka (§23 Continue Learning). */
    public function nextItem(LmsEnrollment $enrollment, ?array $completedIds = null): ?LmsLearningItem
    {
        $completedIds ??= $this->completions->completedItemIds($enrollment);

        foreach ($this->countableItems((int) $enrollment->course_id) as $item) {
            if (in_array((int) $item->id, $completedIds, true)) {
                continue;
            }

            if (! $this->completions->satisfiesPrerequisites($enrollment, $item)->allowed) {
                continue;
            }

            return $item;
        }

        return null;
    }

    /**
     * Ringkasan progress satu course untuk seorang pelaku — termasuk rincian
     * per section agar UI dapat menampilkan bilah progress bertingkat.
     *
     * @return array<string, mixed>
     */
    public function summary(LmsCourse $course, LmsActor $actor): array
    {
        $enrollment = $course->enrollmentFor($actor->id);
        $items = $this->countableItems((int) $course->id);

        if ($enrollment === null) {
            return [
                'enrolled' => false,
                'progress_percent' => 0,
                'completed_items' => 0,
                'required_items' => $items->count(),
                'total_items' => $items->count(),
                'next_item_id' => null,
                'sections' => [],
            ];
        }

        $progress = $enrollment->progress;
        $completedIds = $this->completions->completedItemIds($enrollment);

        // Baris ringkasan dapat tertinggal (mis. penyelesaian dicatat lewat
        // jalur lain), jadi bila jumlahnya tidak cocok ia dihitung ulang di sini
        // — bukan disajikan sebagai angka yang salah.
        if ($progress === null || $this->isStale($progress, $items, $completedIds)) {
            $progress = $this->recompute($enrollment);
        }

        $sections = $course->sections()->with('items')->get()->map(function ($section) use ($completedIds) {
            $items = $section->items->filter(fn (LmsLearningItem $item) => $this->isCountable($item));
            $done = $items->filter(fn (LmsLearningItem $item) => in_array((int) $item->id, $completedIds, true))->count();

            return [
                'section_id' => (int) $section->id,
                'title' => (string) $section->title,
                'completed_items' => $done,
                'required_items' => $items->count(),
                'progress_percent' => $items->count() === 0 ? 0 : round($done / $items->count() * 100, 2),
            ];
        })->all();

        return [
            'enrolled' => true,
            'enrollment_id' => (int) $enrollment->id,
            'enrollment_status' => $enrollment->status?->value,
            'progress_percent' => (float) $progress->progress_percent,
            'completed_items' => (int) $progress->completed_items,
            'required_items' => (int) $progress->required_items,
            'total_items' => (int) $progress->total_items,
            'next_item_id' => $progress->next_item_id !== null ? (int) $progress->next_item_id : null,
            'last_item_id' => $progress->last_item_id !== null ? (int) $progress->last_item_id : null,
            'completed_at' => $progress->completed_at?->toIso8601String(),
            'sections' => $sections,
        ];
    }

    /**
     * Apakah ringkasan tersimpan sudah tidak cocok dengan data penyelesaian?
     *
     * @param  Collection<int, LmsLearningItem>  $items
     * @param  list<int>  $completedIds
     */
    protected function isStale(LmsCourseProgress $progress, Collection $items, array $completedIds): bool
    {
        $required = $items->where('is_required', true)->values();
        $denominator = $required->isNotEmpty() ? $required : $items;

        $completed = $denominator->filter(
            fn (LmsLearningItem $item) => in_array((int) $item->id, $completedIds, true),
        )->count();

        return (int) $progress->completed_items !== $completed
            || (int) $progress->required_items !== $denominator->count();
    }

    /** Catat item terakhir yang dibuka (dipakai "Continue Learning"). */
    public function touchLastItem(LmsEnrollment $enrollment, LmsLearningItem $item): void
    {
        LmsCourseProgress::query()->updateOrCreate(
            ['enrollment_id' => $enrollment->id],
            ['course_id' => $enrollment->course_id, 'user_id' => $enrollment->user_id, 'last_item_id' => $item->id],
        );

        $enrollment->forceFill(['last_accessed_at' => now()])->save();
    }

    /** @return Collection<int, LmsLearningItem> */
    public function countableItems(int $courseId): Collection
    {
        return LmsLearningItem::query()
            ->where('course_id', $courseId)
            ->where('visibility', ContentVisibility::VISIBLE->value)
            ->where('completion_type', '!=', CompletionType::NONE->value)
            ->orderBy('section_id')
            ->orderBy('sort_order')
            ->get();
    }

    protected function isCountable(LmsLearningItem $item): bool
    {
        return $item->visibility === ContentVisibility::VISIBLE
            && ($item->completion_type ?? CompletionType::MANUAL) !== CompletionType::NONE;
    }

    protected function nextStatus(LmsEnrollment $enrollment, LmsCourseProgress $progress): EnrollmentStatus
    {
        if (in_array($enrollment->status, [EnrollmentStatus::SUSPENDED, EnrollmentStatus::CANCELLED, EnrollmentStatus::PENDING], true)) {
            return $enrollment->status;
        }

        return $progress->completed_at !== null ? EnrollmentStatus::COMPLETED : EnrollmentStatus::ACTIVE;
    }
}
