<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Contracts\TeacherProviderInterface;
use App\Modules\LMS\Domain\Enums\CourseStatus;
use App\Modules\LMS\Domain\Enums\CourseVisibility;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsCategory;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsLearningItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pencarian global LMS (§31): course, materi, guru, dan kategori.
 *
 * Hasil selalu dibatasi cakupan pemanggil: guest hanya course publik, siswa
 * hanya course yang boleh dilihatnya, guru/admin seluruh course yang dikelola.
 */
class CourseSearchService
{
    public function __construct(private readonly TeacherProviderInterface $teachers) {}

    /** @return array<string, mixed> */
    public function search(string $term, ?LmsActor $actor, array $filters = [], int $limit = 10): array
    {
        $term = trim($term);

        return [
            'term' => $term,
            'courses' => $this->courses($term, $actor, $filters, $limit),
            'items' => $term === '' ? [] : $this->items($term, $actor, $limit),
            'teachers' => $term === '' ? [] : $this->teacherResults($term, $limit),
            'categories' => $this->categories($term, $limit),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function courses(string $term, ?LmsActor $actor, array $filters = [], int $limit = 10): array
    {
        $query = LmsCourse::query()
            ->with(['category:id,name', 'teachers:id,course_id,user_id,name,is_primary'])
            ->withCount(['sections', 'items', 'students as enrolled_students_count']);

        if ($actor === null || $actor->isStudent() || $actor->isParent()) {
            $query->where(fn (Builder $q) => $q
                ->where(fn (Builder $inner) => $inner
                    ->where('visibility', CourseVisibility::PUBLIC->value)
                    ->where('status', CourseStatus::PUBLISHED->value))
                ->orWhere(fn (Builder $inner) => $inner
                    ->where('visibility', CourseVisibility::INSTITUTION->value)
                    ->where('status', CourseStatus::PUBLISHED->value)));
        }

        $query
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%")
                ->orWhere('short_description', 'like', "%{$term}%")))
            ->when(($filters['category_id'] ?? null) !== null && $filters['category_id'] !== '', fn (Builder $q) => $q->where('category_id', (int) $filters['category_id']))
            ->when(($filters['level'] ?? null) !== null && $filters['level'] !== '', fn (Builder $q) => $q->where('level', $filters['level']))
            ->when(($filters['course_type'] ?? null) !== null && $filters['course_type'] !== '', fn (Builder $q) => $q->where('course_type', $filters['course_type']))
            ->when(($filters['teacher_id'] ?? null) !== null && $filters['teacher_id'] !== '', fn (Builder $q) => $q
                ->whereHas('teachers', fn (Builder $t) => $t->where('employee_id', (int) $filters['teacher_id'])));

        return $query->orderByDesc('is_featured')->orderBy('name')->limit($limit)->get()
            ->map(fn (LmsCourse $course) => [
                'id' => (int) $course->id,
                'name' => (string) $course->name,
                'slug' => (string) $course->slug,
                'code' => (string) $course->code,
                'short_description' => $course->short_description,
                'thumbnail' => $course->thumbnail,
                'level' => $course->level?->value,
                'course_type' => $course->course_type?->value,
                'status' => $course->status?->value,
                'visibility' => $course->visibility?->value,
                'category' => $course->category?->name,
                'teachers' => $course->teachers->pluck('name')->filter()->values()->all(),
                'sections_count' => (int) $course->sections_count,
                'items_count' => (int) $course->items_count,
                'students_count' => (int) $course->enrolled_students_count,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function items(string $term, ?LmsActor $actor, int $limit = 10): array
    {
        return LmsLearningItem::query()
            ->with(['course:id,name,slug,visibility,status', 'section:id,title'])
            ->where(fn (Builder $q) => $q->where('title', 'like', "%{$term}%")->orWhere('description', 'like', "%{$term}%"))
            ->when($actor === null, fn (Builder $q) => $q->whereHas('course', fn (Builder $c) => $c
                ->where('visibility', CourseVisibility::PUBLIC->value)))
            ->orderBy('title')
            ->limit($limit)
            ->get()
            ->map(fn (LmsLearningItem $item) => [
                'id' => (int) $item->id,
                'title' => (string) $item->title,
                'item_type' => $item->item_type?->value,
                'resource_type' => $item->resource_type?->value,
                'activity_type' => $item->activity_type?->value,
                'course_id' => (int) $item->course_id,
                'course_name' => $item->course?->name,
                'section_name' => $item->section?->title,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function teacherResults(string $term, int $limit = 8): array
    {
        return array_map(fn ($teacher) => $teacher->toArray(), $this->teachers->search($term, $limit));
    }

    /** @return list<array<string, mixed>> */
    public function categories(string $term, int $limit = 8): array
    {
        return LmsCategory::query()
            ->active()
            ->when($term !== '', fn (Builder $q) => $q->where('name', 'like', "%{$term}%"))
            ->withCount('courses')
            ->orderBy('sort_order')
            ->limit($limit)
            ->get()
            ->map(fn (LmsCategory $category) => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'slug' => (string) $category->slug,
                'image' => $category->image,
                'courses_count' => (int) $category->courses_count,
            ])
            ->all();
    }

    /** Saran untuk kotak pencarian. */
    public function suggestions(string $term, ?LmsActor $actor, int $limit = 6): array
    {
        return $this->courses($term, $actor, [], $limit);
    }
}
