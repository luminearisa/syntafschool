<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Contracts\AcademicCourseProviderInterface;
use App\Modules\LMS\Domain\Contracts\ClassProviderInterface;
use App\Modules\LMS\Domain\Contracts\TeacherProviderInterface;
use App\Modules\LMS\Domain\Enums\ActivityType;
use App\Modules\LMS\Domain\Enums\CompletionType;
use App\Modules\LMS\Domain\Enums\ContentVisibility;
use App\Modules\LMS\Domain\Enums\CourseLevel;
use App\Modules\LMS\Domain\Enums\CourseScope;
use App\Modules\LMS\Domain\Enums\CourseStatus;
use App\Modules\LMS\Domain\Enums\CourseType;
use App\Modules\LMS\Domain\Enums\CourseVisibility;
use App\Modules\LMS\Domain\Enums\EnrollmentMethod;
use App\Modules\LMS\Domain\Enums\EnrollmentRole;
use App\Modules\LMS\Domain\Enums\ItemType;
use App\Modules\LMS\Domain\Enums\ResourceType;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Infrastructure\Support\HtmlSanitizer;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseSection;
use App\Modules\LMS\Models\LmsLearningItem;
use App\Modules\LMS\Models\LmsResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pembuatan & pengelolaan course (§5–§9).
 *
 * Course SELALU lahir sebagai DRAFT (§7): penerbitan adalah keputusan terpisah
 * di `CoursePublishService` supaya guru dapat menyiapkan materi dulu.
 */
class CourseService
{
    public function __construct(
        private readonly ClassProviderInterface $classes,
        private readonly AcademicCourseProviderInterface $academic,
        private readonly TeacherProviderInterface $teachers,
        private readonly HtmlSanitizer $sanitizer,
        private readonly CourseEnrollmentService $enrollments,
    ) {}

    // --------------------------------------------------------------------
    // Kueri
    // --------------------------------------------------------------------

    public function query(LmsActor $actor, CourseScope $scope, array $filters = []): Builder
    {
        $query = LmsCourse::query()
            ->with(['category:id,name,slug', 'teachers:id,course_id,user_id,employee_id,name,role,is_primary'])
            ->withCount(['students as enrolled_students_count', 'sections', 'items']);

        $this->applyScope($query, $actor, $scope);

        $query
            ->when(($filters['search'] ?? null) !== null && $filters['search'] !== '', function (Builder $q) use ($filters): void {
                $term = trim((string) $filters['search']);
                $q->where(fn (Builder $inner) => $inner
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhere('short_description', 'like', "%{$term}%"));
            })
            ->when(($filters['category_id'] ?? null) !== null && $filters['category_id'] !== '', fn (Builder $q) => $q->where('category_id', (int) $filters['category_id']))
            ->when(($filters['status'] ?? null) !== null && $filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(($filters['visibility'] ?? null) !== null && $filters['visibility'] !== '', fn (Builder $q) => $q->where('visibility', $filters['visibility']))
            ->when(($filters['course_type'] ?? null) !== null && $filters['course_type'] !== '', fn (Builder $q) => $q->where('course_type', $filters['course_type']))
            ->when(($filters['level'] ?? null) !== null && $filters['level'] !== '', fn (Builder $q) => $q->where('level', $filters['level']))
            ->when(($filters['academic_year_id'] ?? null) !== null && $filters['academic_year_id'] !== '', fn (Builder $q) => $q->where('academic_year_id', (int) $filters['academic_year_id']))
            ->when(($filters['class_id'] ?? null) !== null && $filters['class_id'] !== '', fn (Builder $q) => $q->where('class_id', (int) $filters['class_id']))
            ->when(($filters['teacher_id'] ?? null) !== null && $filters['teacher_id'] !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('teacher_id', (int) $filters['teacher_id'])
                ->orWhereHas('teachers', fn (Builder $t) => $t->where('employee_id', (int) $filters['teacher_id']))))
            ->when(($filters['featured'] ?? null) === true, fn (Builder $q) => $q->where('is_featured', true));

        $sort = (string) ($filters['sort'] ?? 'updated');
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'name' => $query->orderBy('name', $direction),
            'code' => $query->orderBy('code', $direction),
            'start_date' => $query->orderBy('start_date', $direction),
            'popular' => $query->orderByDesc('enrolled_students_count'),
            default => $query->orderBy('updated_at', $direction),
        };

        return $query;
    }

    protected function applyScope(Builder $query, LmsActor $actor, CourseScope $scope): void
    {
        match ($scope) {
            CourseScope::PUBLIC => $query->publiclyVisible(),
            CourseScope::ENROLLED => $query->whereHas('enrollments', fn (Builder $q) => $q
                ->where('user_id', $actor->id)
                ->whereIn('status', ['ACTIVE', 'COMPLETED'])),
            CourseScope::MANAGED => $actor->can('lms.manage')
                ? $query
                : $query->where(fn (Builder $q) => $q
                    ->where('created_by', $actor->id)
                    ->orWhere('teacher_id', $actor->employeeId)
                    ->orWhereHas('teachers', fn (Builder $t) => $t->where('user_id', $actor->id))),
            CourseScope::ALL => $query,
        };
    }

    // --------------------------------------------------------------------
    // Mutasi
    // --------------------------------------------------------------------

    public function create(LmsActor $actor, array $data): LmsCourse
    {
        $this->assertAcademicLinkIsCoherent($data);

        $course = LmsCourse::create([
            'category_id' => $data['category_id'] ?? null,
            'academic_course_id' => $data['academic_course_id'] ?? null,
            'academic_year_id' => $data['academic_year_id'] ?? $this->classes->activeAcademicYearId(),
            'semester_id' => $data['semester_id'] ?? null,
            'program_id' => $data['program_id'] ?? null,
            'class_id' => $data['class_id'] ?? null,
            'teacher_id' => $data['teacher_id'] ?? null,
            'code' => $data['code'] ?? $this->generateCode($data),
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($data['slug'] ?? $data['name']),
            'short_description' => $data['short_description'] ?? null,
            'description' => $this->sanitizer->clean($data['description'] ?? null),
            'learning_objectives' => $data['learning_objectives'] ?? null,
            'thumbnail' => $data['thumbnail'] ?? null,
            'cover_image' => $data['cover_image'] ?? null,
            'course_type' => $data['course_type'] ?? CourseType::GENERAL->value,
            'level' => $data['level'] ?? CourseLevel::BEGINNER->value,
            'language' => $data['language'] ?? 'id',
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'status' => CourseStatus::DRAFT->value,
            'visibility' => $data['visibility'] ?? ($data['class_id'] ?? null ? CourseVisibility::ENROLLED_ONLY->value : CourseVisibility::PRIVATE->value),
            'enrollment_method' => $data['enrollment_method'] ?? ($data['class_id'] ?? null ? EnrollmentMethod::ACADEMIC->value : EnrollmentMethod::MANUAL->value),
            'is_featured' => $data['is_featured'] ?? false,
            'max_students' => $data['max_students'] ?? null,
            'estimated_minutes' => $data['estimated_minutes'] ?? null,
            'created_by' => $actor->id,
        ]);

        $this->syncTeachers($course, $data, $actor);

        // Enrollment peserta dari rombel SIAKAD tidak perlu diketik ulang (§6, §17).
        if (($data['sync_academic_enrollments'] ?? true) && $course->class_id !== null) {
            $this->enrollments->syncAcademicEnrollments($course, $actor);
        }

        return $course->fresh(['category', 'teachers']);
    }

    public function update(LmsCourse $course, array $data, LmsActor $actor): LmsCourse
    {
        $classChanged = array_key_exists('class_id', $data)
            && (int) ($data['class_id'] ?? 0) !== (int) $course->class_id;

        $previousClassId = (int) $course->class_id;

        foreach ([
            'category_id', 'academic_course_id', 'academic_year_id', 'semester_id', 'program_id',
            'class_id', 'teacher_id', 'name', 'short_description', 'learning_objectives',
            'thumbnail', 'cover_image', 'course_type', 'level', 'language',
            'start_date', 'end_date', 'visibility', 'enrollment_method', 'is_featured',
            'max_students', 'estimated_minutes',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $course->{$field} = $data[$field];
            }
        }

        if (array_key_exists('description', $data)) {
            $course->description = $this->sanitizer->clean($data['description']);
        }

        if (array_key_exists('code', $data) && $data['code'] !== null) {
            $course->code = $this->uniqueCode($data['code'], $course->id);
        }

        if (array_key_exists('slug', $data) && $data['slug'] !== null) {
            $course->slug = $this->uniqueSlug($data['slug'], $course->id);
        }

        $course->save();

        if (array_key_exists('teachers', $data)) {
            $this->syncTeachers($course, $data, $actor);
        }

        // Perpindahan rombel memicu sinkronisasi peserta dari SIAKAD.
        if ($classChanged && ($data['sync_academic_enrollments'] ?? true)) {
            $this->enrollments->syncAcademicEnrollments($course->fresh(), $actor, $previousClassId);
        }

        return $course->fresh(['category', 'teachers']);
    }

    public function duplicate(LmsCourse $course, LmsActor $actor, bool $withContent = true): LmsCourse
    {
        $copy = $course->replicate(['created_at', 'updated_at', 'published_at', 'archived_at']);
        $copy->name = $course->name.' (salinan)';
        $copy->code = $this->uniqueCode($course->code.'-COPY');
        $copy->slug = $this->uniqueSlug($course->slug.'-copy');
        $copy->status = CourseStatus::DRAFT->value;
        $copy->published_at = null;
        $copy->archived_at = null;
        $copy->created_by = $actor->id;
        $copy->save();

        if ($withContent) {
            foreach ($course->sections as $section) {
                $newSection = $section->replicate(['created_at', 'updated_at']);
                $newSection->course_id = $copy->id;
                $newSection->save();

                foreach ($section->items as $item) {
                    $newItem = $item->replicate(['created_at', 'updated_at']);
                    $newItem->course_id = $copy->id;
                    $newItem->section_id = $newSection->id;
                    $newItem->save();

                    $resource = LmsResource::query()->where('learning_item_id', $item->id)->first();

                    if ($resource !== null) {
                        $newResource = $resource->replicate(['learning_item_id', 'created_at', 'updated_at']);
                        $newResource->learning_item_id = $newItem->id;
                        $newResource->save();
                    }
                }
            }
        }

        return $copy->fresh(['category']);
    }

    public function delete(LmsCourse $course): void
    {
        $course->delete();
    }

    /** Guru yang ditugaskan pada course (§36 audit: teacher assigned). */
    public function syncTeachers(LmsCourse $course, array $data, LmsActor $actor): void
    {
        $assignments = $data['teachers'] ?? null;

        if ($assignments === null && array_key_exists('teacher_id', $data) && $data['teacher_id'] !== null) {
            $assignments = [['teacher_id' => $data['teacher_id'], 'is_primary' => true]];
        }

        if ($assignments === null) {
            return;
        }

        foreach (array_values($assignments) as $index => $assignment) {
            $teacher = isset($assignment['teacher_id']) && $assignment['teacher_id'] !== null
                ? $this->teachers->find((int) $assignment['teacher_id'])
                : null;

            if ($teacher === null) {
                continue;
            }

            $isPrimary = (bool) ($assignment['is_primary'] ?? $index === 0);

            $course->teachers()->updateOrCreate(
                ['user_id' => $teacher->userId],
                [
                    'employee_id' => $teacher->id,
                    'name' => $teacher->name,
                    'role' => EnrollmentRole::TEACHER->value,
                    'is_primary' => $isPrimary,
                ],
            );

            if ($isPrimary) {
                $course->forceFill(['teacher_id' => $teacher->id])->save();
            }

            // Pengajar otomatis menjadi anggota course dengan peran TEACHER.
            if ($teacher->userId !== null) {
                $this->enrollments->enroll($course, [
                    'user_id' => $teacher->userId,
                    'teacher_id' => $teacher->id,
                    'role' => EnrollmentRole::TEACHER->value,
                    'enrollment_method' => EnrollmentMethod::MANUAL->value,
                ], $actor);
            }
        }
    }

    // --------------------------------------------------------------------
    // Statistik & pemulihan pembelajaran
    // --------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function statistics(LmsCourse $course): array
    {
        $students = $course->enrollments()->where('role', EnrollmentRole::STUDENT->value);
        $studentCount = (int) (clone $students)->count();

        $completed = (int) (clone $students)->whereNotNull('completed_at')->count();
        $items = $course->items()->count();
        $countable = $course->items()
            ->where('visibility', ContentVisibility::VISIBLE->value)
            ->where('completion_type', '!=', CompletionType::NONE->value)
            ->count();

        return [
            'students' => $studentCount,
            'active_students' => (int) (clone $students)->where('status', 'ACTIVE')->count(),
            'completed_students' => $completed,
            'completion_rate' => $studentCount === 0 ? 0 : round($completed / $studentCount * 100, 2),
            'average_progress' => round((float) ($students->avg('progress_percent') ?? 0), 2),
            'teachers' => $course->teachers()->count(),
            'sections' => $course->sections()->count(),
            'items' => $items,
            'countable_items' => $countable,
            'activities' => $course->items()->where('item_type', ItemType::ACTIVITY->value)->count(),
            'resources' => $course->items()->where('item_type', ItemType::RESOURCE->value)->count(),
        ];
    }

    /** Ringkasan akademik SIAKAD yang terkait (§6). */
    public function academicSummary(LmsCourse $course): ?array
    {
        return $this->academic->summary(
            $course->academic_course_id !== null ? (int) $course->academic_course_id : null,
            $course->class_id !== null ? (int) $course->class_id : null,
            $course->academic_year_id !== null ? (int) $course->academic_year_id : null,
        );
    }

    /**
     * "Continue Learning" (§23) — langsung membuka item yang belum selesai.
     *
     * @return array<string, mixed>
     */
    public function resume(LmsCourse $course, LmsActor $actor): array
    {
        $enrollment = $course->enrollmentFor($actor->id);

        if ($enrollment === null) {
            return ['enrolled' => false, 'next_item_id' => null, 'progress_percent' => 0];
        }

        $progress = $enrollment->progress;

        return [
            'enrolled' => true,
            'enrollment_id' => (int) $enrollment->id,
            'next_item_id' => $progress?->next_item_id !== null ? (int) $progress->next_item_id : null,
            'last_item_id' => $progress?->last_item_id !== null ? (int) $progress->last_item_id : null,
            'progress_percent' => (float) ($progress?->progress_percent ?? 0),
            'completed_items' => (int) ($progress?->completed_items ?? 0),
            'required_items' => (int) ($progress?->required_items ?? 0),
        ];
    }

    // --------------------------------------------------------------------
    // Lookups & penomoran
    // --------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function lookups(LmsActor $actor, LmsCategoryService $categories): array
    {
        return [
            'mode' => $actor->standalone ? 'standalone' : 'integrated',
            'categories' => $categories->options(true),
            'academic_years' => $this->classes->academicYearOptions(),
            'semesters' => $this->classes->semesterOptions(),
            'programs' => $this->classes->programOptions(),
            'classes' => $this->classes->options(),
            'subjects' => $this->classes->subjectOptions(),
            'teachers' => array_map(fn ($teacher) => $teacher->toArray(), $this->teachers->search('')),
            'enums' => [
                'course_type' => $this->enumOptions(CourseType::cases()),
                'status' => $this->enumOptions(CourseStatus::cases()),
                'visibility' => $this->enumOptions(CourseVisibility::cases()),
                'level' => $this->enumOptions(CourseLevel::cases()),
                'enrollment_method' => $this->enumOptions(EnrollmentMethod::cases()),
                'enrollment_role' => $this->enumOptions(EnrollmentRole::cases()),
                'enrollment_status' => $this->enumOptions(\App\Modules\LMS\Domain\Enums\EnrollmentStatus::cases()),
                'item_type' => $this->enumOptions(ItemType::cases()),
                'resource_type' => $this->enumOptions(ResourceType::cases()),
                'activity_type' => $this->enumOptions(ActivityType::cases()),
                'completion_type' => $this->enumOptions(CompletionType::cases()),
            ],
        ];
    }

    /** @param list<object> $cases */
    protected function enumOptions(array $cases): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => method_exists($case, 'label') ? $case->label() : $case->value,
            'icon' => method_exists($case, 'icon') ? $case->icon() : null,
            'graded' => method_exists($case, 'isGraded') ? $case->isGraded() : null,
        ], $cases);
    }

    /** Kode course mengikuti pola SIAKAD: MAT101-XIPA1-2026 (§6). */
    public function generateCode(array $data): string
    {
        $segments = [];

        if (! empty($data['academic_course_id'])) {
            $subject = $this->academic->find((int) $data['academic_course_id']);
            $segments[] = $subject['code'] ?? 'ACAD';
        }

        if (! empty($data['class_id'])) {
            $class = $this->classes->find((int) $data['class_id']);
            $segments[] = Str::upper(Str::slug((string) ($class['code'] ?? $class['name'] ?? 'KELAS'), ''));
        }

        $yearId = $data['academic_year_id'] ?? $this->classes->activeAcademicYearId();

        if ($yearId !== null) {
            foreach ($this->classes->academicYearOptions() as $year) {
                if ((int) $year['id'] === (int) $yearId) {
                    $segments[] = Str::upper(Str::slug((string) $year['name'], ''));
                    break;
                }
            }
        }

        if ($segments === []) {
            $segments[] = 'LMS';
            $segments[] = (string) (LmsCourse::query()->count() + 1);
        }

        return $this->uniqueCode(implode('-', $segments));
    }

    public function uniqueCode(string $code, ?int $ignoreId = null): string
    {
        $base = Str::upper(Str::slug($code, '-'));
        $candidate = $base;
        $counter = 1;

        while (LmsCourse::withTrashed()
            ->where('code', $candidate)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()) {
            $candidate = $base.'-'.(++$counter);
        }

        return $candidate;
    }

    public function uniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value) ?: 'course';
        $candidate = $base;
        $counter = 1;

        while (LmsCourse::withTrashed()
            ->where('slug', $candidate)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()) {
            $candidate = $base.'-'.(++$counter);
        }

        return $candidate;
    }

    protected function assertAcademicLinkIsCoherent(array $data): void
    {
        $courseType = $data['course_type'] ?? CourseType::GENERAL->value;

        if ($courseType !== CourseType::ACADEMIC->value) {
            return;
        }

        if (empty($data['academic_course_id'])) {
            throw ValidationException::withMessages([
                'academic_course_id' => 'Course akademik wajib ditautkan ke mata pelajaran SIAKAD.',
            ]);
        }
    }

    /** Section pertama yang belum punya item — petunjuk "lengkapi struktur". */
    public function firstIncompleteSection(LmsCourse $course): ?LmsCourseSection
    {
        return $course->sections()->withCount('items')->get()
            ->firstWhere('items_count', 0);
    }

    /** Item tanpa completion type dianggap belum siap dinilai. */
    public function itemsMissingCompletion(LmsCourse $course): int
    {
        return $course->items()->whereNull('completion_type')->count();
    }
}
