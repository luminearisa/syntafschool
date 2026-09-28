<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Contracts\AcademicActivityFeedInterface;
use App\Modules\LMS\Domain\Contracts\ClassProviderInterface;
use App\Modules\LMS\Domain\Contracts\StudentProviderInterface;
use App\Modules\LMS\Domain\Contracts\TeacherProviderInterface;
use App\Modules\LMS\Domain\Enums\CourseStatus;
use App\Modules\LMS\Domain\Enums\EnrollmentRole;
use App\Modules\LMS\Domain\Enums\EnrollmentStatus;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsLearner;
use App\Modules\LMS\Models\LmsActivityCompletion;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseAnnouncement;
use App\Modules\LMS\Models\LmsCourseProgress;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsLearningItem;
use App\Modules\LMS\Models\LmsLearningView;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Dashboard per peran (§26–§29).
 *
 * Semua angka dihitung dari data LMS sendiri; satu-satunya sumber luar adalah
 * `AcademicActivityFeedInterface` untuk tugas & nilai ERP, dan ia mengembalikan
 * array kosong di Standalone Mode — sehingga dashboard tidak pernah gagal
 * hanya karena SIAKAD tidak ada.
 */
class LmsDashboardService
{
    public function __construct(
        private readonly StudentProviderInterface $students,
        private readonly TeacherProviderInterface $teachers,
        private readonly ClassProviderInterface $classes,
        private readonly AcademicActivityFeedInterface $feed,
        private readonly LmsCalendarService $calendar,
        private readonly LmsIntegrationService $integration,
    ) {}

    /** Dashboard mana yang tersedia untuk pelaku ini (§3). */
    public function forActor(LmsActor $actor): array
    {
        return match (true) {
            $actor->isParent() => $this->parent($actor),
            $actor->isStudent() => $this->student($actor),
            $actor->isTeacher() => $this->teacher($actor),
            $actor->isAdmin() => $this->admin($actor),
            default => $this->guestPreview($actor),
        };
    }

    // --------------------------------------------------------------------
    // STUDENT (§26)
    // --------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function student(LmsActor $actor): array
    {
        $enrollments = $this->studentEnrollments($actor);

        $cards = $enrollments
            ->sortByDesc(fn (LmsEnrollment $enrollment) => $enrollment->last_accessed_at?->timestamp ?? 0)
            ->take(3)
            ->map(fn (LmsEnrollment $enrollment) => $this->continueLearningCard($enrollment))
            ->values()
            ->all();

        $myCourses = $enrollments->map(fn (LmsEnrollment $enrollment) => $this->courseCard($enrollment))->values()->all();
        $learner = $this->students->forUser($actor);

        $completedItems = (int) LmsActivityCompletion::query()
            ->where('user_id', $actor->id)
            ->where('status', 'COMPLETE')
            ->count();

        return [
            'role' => 'STUDENT',
            'greeting' => $this->greeting().', '.$actor->name,
            'learner' => $learner?->toArray(),
            'stats' => [
                'courses' => $enrollments->count(),
                'completed_courses' => $enrollments->where('status', EnrollmentStatus::COMPLETED)->count(),
                'completed_items' => $completedItems,
                'average_progress' => $enrollments->isEmpty() ? 0 : round((float) $enrollments->avg('progress_percent'), 2),
            ],
            'continue_learning' => $cards,
            'my_courses' => $myCourses,
            'upcoming' => $this->studentUpcoming($actor, $learner),
            'recent_activity' => $this->studentRecentActivity($actor),
            'announcements' => $this->announcementsFor($enrollments->pluck('course_id')->all()),
            'bookmarks' => $this->bookmarkCount($actor),
            'calendar' => $this->calendar->agenda($actor),
        ];
    }

    // --------------------------------------------------------------------
    // TEACHER (§27)
    // --------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function teacher(LmsActor $actor): array
    {
        $courses = $this->managedCourses($actor);
        $courseIds = $courses->pluck('id')->all();
        $employeeId = $actor->employeeId;

        $counters = $this->feed->gradingCounters($actor->can('lms.manage') ? null : $employeeId);

        $activeStudents = (int) LmsEnrollment::query()
            ->whereIn('course_id', $courseIds)
            ->where('role', EnrollmentRole::STUDENT->value)
            ->where('status', EnrollmentStatus::ACTIVE->value)
            ->count();

        $activityToday = (int) LmsActivityCompletion::query()
            ->whereIn('course_id', $courseIds)
            ->where('status', 'COMPLETE')
            ->whereDate('completed_at', now()->toDateString())
            ->count();

        $pendingItems = (int) LmsLearningItem::query()
            ->whereIn('course_id', $courseIds)
            ->whereNull('completion_type')
            ->count();

        return [
            'role' => 'TEACHER',
            'greeting' => $this->greeting().', '.$actor->name,
            'teacher' => $this->teachers->forUser($actor)?->toArray(),
            'stats' => [
                'courses' => $courses->count(),
                'published_courses' => $courses->where('status', CourseStatus::PUBLISHED)->count(),
                'draft_courses' => $courses->where('status', CourseStatus::DRAFT)->count(),
                'active_students' => $activeStudents,
                'pending_grading' => $counters['pending'],
                'graded' => $counters['graded'],
                'completions_today' => $activityToday,
                'items_needing_setup' => $pendingItems,
            ],
            'my_courses' => $courses->take(6)->map(fn (LmsCourse $course) => $this->managementCard($course))->values()->all(),
            'pending_grading' => $this->feed->pendingGrading($actor->can('lms.manage') ? null : $employeeId, 8),
            'recent_submissions' => $this->feed->recentSubmissions($actor->can('lms.manage') ? null : $employeeId, 6),
            'upcoming_deadlines' => $this->feed->upcomingForClasses(
                $courses->pluck('class_id')->filter()->map('intval')->unique()->all(),
                5,
            ),
            'course_activity' => $this->teacherCourseActivity($courseIds),
            'announcements' => LmsCourseAnnouncement::query()
                ->with('course:id,name')
                ->whereIn('course_id', $courseIds)
                ->published()
                ->ordered()
                ->limit(5)
                ->get()
                ->map(fn (LmsCourseAnnouncement $announcement) => [
                    'id' => (int) $announcement->id,
                    'title' => (string) $announcement->title,
                    'course_id' => (int) $announcement->course_id,
                    'course_name' => $announcement->course?->name,
                    'published_at' => $announcement->published_at?->toIso8601String(),
                    'pinned' => (bool) $announcement->pinned,
                ])
                ->all(),
            'academic_coverage' => $this->integration->academicCoverage($actor),
        ];
    }

    // --------------------------------------------------------------------
    // PARENT (§28)
    // --------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function parent(LmsActor $actor): array
    {
        $children = $this->students->childrenOf($actor);

        return [
            'role' => 'PARENT',
            'greeting' => $this->greeting().', '.$actor->name,
            'stats' => [
                'children' => count($children),
                'courses' => count($children) === 0 ? 0 : LmsEnrollment::query()
                    ->whereIn('user_id', collect($children)->pluck('userId')->filter()->all())
                    ->distinct('course_id')
                    ->count('course_id'),
            ],
            'children' => array_map(fn (LmsLearner $child) => $this->childSummary($actor, $child), $children),
            'policy' => [
                'can_view_grades' => (bool) config('lms.parent.can_view_grades', true),
                'can_view_feedback' => (bool) config('lms.parent.can_view_feedback', true),
            ],
        ];
    }

    /**
     * Detail belajar satu anak. Isolasi anak ditegakkan di sini: wali yang
     * meminta anak bukan miliknya menerima 403 dari controller (§44).
     *
     * @return array<string, mixed>
     */
    public function childOverview(LmsActor $actor, int $studentId): array
    {
        $child = $this->students->find($studentId);

        if ($child === null) {
            return [];
        }

        $enrollments = $child->userId !== null
            ? LmsEnrollment::query()
                ->with(['course:id,name,code,slug,thumbnail,status,visibility', 'progress'])
                ->where('user_id', $child->userId)
                ->orderByDesc('last_accessed_at')
                ->get()
            : new Collection();

        $courses = $enrollments->map(function (LmsEnrollment $enrollment): array {
            $course = $enrollment->course;

            return [
                'enrollment_id' => (int) $enrollment->id,
                'course_id' => (int) $enrollment->course_id,
                'name' => $course?->name,
                'code' => $course?->code,
                'thumbnail' => $course?->thumbnail,
                'status' => $enrollment->status?->value,
                'progress_percent' => (float) ($enrollment->progress->progress_percent ?? $enrollment->progress_percent),
                'completed_items' => (int) ($enrollment->progress->completed_items ?? 0),
                'required_items' => (int) ($enrollment->progress->required_items ?? 0),
                'last_accessed_at' => $enrollment->last_accessed_at?->toIso8601String(),
                'next_item_id' => $enrollment->progress?->next_item_id !== null ? (int) $enrollment->progress->next_item_id : null,
            ];
        })->values()->all();

        $upcoming = $this->feed->upcomingForStudent($studentId, 6);
        $grades = config('lms.parent.can_view_grades', true)
            ? $this->feed->gradesForStudent($studentId)
            : ['count' => 0, 'average' => null, 'recent' => [], 'hidden' => true];

        $announcements = $this->announcementsFor($enrollments->pluck('course_id')->all());

        return [
            'child' => $child->toArray(),
            'stats' => [
                'courses' => count($courses),
                'completed_courses' => $enrollments->where('status', EnrollmentStatus::COMPLETED)->count(),
                'average_progress' => $courses === [] ? 0 : round(collect($courses)->avg('progress_percent'), 2),
                'upcoming_deadlines' => count($upcoming),
            ],
            'courses' => $courses,
            'upcoming' => $upcoming,
            'grades' => $grades,
            'announcements' => $announcements,
            'certificates' => [],
            'recent_activity' => $this->studentRecentActivityFor($child->userId),
        ];
    }

    // --------------------------------------------------------------------
    // ADMIN (§29)
    // --------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function admin(LmsActor $actor): array
    {
        $courses = LmsCourse::query();
        $totalCourses = (int) (clone $courses)->count();
        $published = (int) (clone $courses)->where('status', CourseStatus::PUBLISHED->value)->count();
        $drafts = (int) (clone $courses)->where('status', CourseStatus::DRAFT->value)->count();
        $archived = (int) (clone $courses)->where('status', CourseStatus::ARCHIVED->value)->count();

        $enrollments = LmsEnrollment::query();
        $studentEnrollments = (clone $enrollments)->where('role', EnrollmentRole::STUDENT->value)->count();
        $completedEnrollments = (clone $enrollments)
            ->where('role', EnrollmentRole::STUDENT->value)
            ->whereNotNull('completed_at')
            ->count();

        $last14 = collect(range(13, 0))->map(fn (int $daysAgo) => now()->subDays($daysAgo)->toDateString());

        $activityTrend = $last14->map(function (string $date): array {
            return [
                'date' => $date,
                'completions' => (int) LmsActivityCompletion::query()->whereDate('completed_at', $date)->count(),
                'views' => (int) LmsLearningView::query()->whereDate('viewed_at', $date)->count(),
            ];
        })->all();

        return [
            'role' => 'ADMIN',
            'greeting' => $this->greeting().', '.$actor->name,
            'stats' => [
                'total_courses' => $totalCourses,
                'active_courses' => $published,
                'draft_courses' => $drafts,
                'archived_courses' => $archived,
                'students' => (int) LmsEnrollment::query()->where('role', EnrollmentRole::STUDENT->value)->distinct('user_id')->count('user_id'),
                'teachers' => (int) LmsEnrollment::query()->where('role', EnrollmentRole::TEACHER->value)->distinct('user_id')->count('user_id'),
                'enrollments' => (int) $studentEnrollments,
                'completion_rate' => $studentEnrollments === 0 ? 0 : round($completedEnrollments / $studentEnrollments * 100, 2),
                'pending_content' => (int) LmsCourse::query()->whereDoesntHave('items')->count(),
                'active_learning_7d' => (int) LmsLearningView::query()->where('viewed_at', '>=', now()->subDays(7))->count(),
                'categories' => (int) \App\Modules\LMS\Models\LmsCategory::query()->count(),
            ],
            'enrollment_trend' => $this->enrollmentTrend(),
            'completion_trend' => $this->completionTrend(),
            'activity_trend' => $activityTrend,
            'most_active_courses' => $this->mostActiveCourses(),
            'inactive_courses' => $this->inactiveCourses(),
            'recent_courses' => LmsCourse::query()
                ->with('category:id,name')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get()
                ->map(fn (LmsCourse $course) => $this->managementCard($course))
                ->all(),
            'integration' => $this->integration->health(),
            'calendar' => $this->calendar->agenda($actor),
        ];
    }

    protected function guestPreview(LmsActor $actor): array
    {
        return [
            'role' => 'GUEST',
            'greeting' => 'Selamat datang di LMS',
            'stats' => ['public_courses' => (int) LmsCourse::query()->publiclyVisible()->count()],
            'courses' => LmsCourse::query()->publiclyVisible()->limit(6)->get()->map(fn (LmsCourse $course) => $this->managementCard($course))->all(),
        ];
    }

    // --------------------------------------------------------------------
    // Potongan data bersama
    // --------------------------------------------------------------------

    /** @return Collection<int, LmsEnrollment> */
    protected function studentEnrollments(LmsActor $actor): Collection
    {
        return LmsEnrollment::query()
            ->with(['course:id,name,code,slug,thumbnail,cover_image,status,visibility,level,short_description', 'progress'])
            ->where('user_id', $actor->id)
            ->where('role', EnrollmentRole::STUDENT->value)
            ->whereIn('status', [EnrollmentStatus::ACTIVE->value, EnrollmentStatus::COMPLETED->value])
            ->get();
    }

    /** @return Collection<int, LmsCourse> */
    protected function managedCourses(LmsActor $actor): Collection
    {
        return LmsCourse::query()
            ->withCount(['students as enrolled_students_count', 'sections', 'items'])
            ->when(! $actor->can('lms.manage'), fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('created_by', $actor->id)
                ->orWhere('teacher_id', $actor->employeeId)
                ->orWhereHas('teachers', fn (Builder $t) => $t->where('user_id', $actor->id))))
            ->orderByDesc('updated_at')
            ->get();
    }

    /** @return array<string, mixed> */
    protected function continueLearningCard(LmsEnrollment $enrollment): array
    {
        $nextItem = $enrollment->progress?->next_item_id !== null
            ? LmsLearningItem::query()->find($enrollment->progress->next_item_id)
            : null;

        return [
            'enrollment_id' => (int) $enrollment->id,
            'course_id' => (int) $enrollment->course_id,
            'name' => $enrollment->course?->name,
            'code' => $enrollment->course?->code,
            'thumbnail' => $enrollment->course?->thumbnail,
            'progress_percent' => (float) ($enrollment->progress->progress_percent ?? $enrollment->progress_percent),
            'completed_items' => (int) ($enrollment->progress->completed_items ?? 0),
            'required_items' => (int) ($enrollment->progress->required_items ?? 0),
            'last_accessed_at' => $enrollment->last_accessed_at?->toIso8601String(),
            'next_item' => $nextItem !== null ? [
                'id' => (int) $nextItem->id,
                'title' => (string) $nextItem->title,
                'resource_type' => $nextItem->resource_type?->value,
                'activity_type' => $nextItem->activity_type?->value,
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    protected function courseCard(LmsEnrollment $enrollment): array
    {
        return $this->continueLearningCard($enrollment) + [
            'status' => $enrollment->status?->value,
            'level' => $enrollment->course?->level?->value,
        ];
    }

    /** @return array<string, mixed> */
    protected function managementCard(LmsCourse $course): array
    {
        return [
            'id' => (int) $course->id,
            'name' => (string) $course->name,
            'code' => (string) $course->code,
            'slug' => (string) $course->slug,
            'thumbnail' => $course->thumbnail,
            'status' => $course->status?->value,
            'visibility' => $course->visibility?->value,
            'category' => $course->category?->name,
            'students_count' => (int) ($course->enrolled_students_count ?? $course->students()->count()),
            'sections_count' => (int) ($course->sections_count ?? $course->sections()->count()),
            'items_count' => (int) ($course->items_count ?? $course->items()->count()),
            'average_progress' => round((float) ($course->students()->avg('progress_percent') ?? 0), 2),
            'updated_at' => $course->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    protected function studentUpcoming(LmsActor $actor, ?LmsLearner $learner): array
    {
        $agenda = $this->calendar->agenda($actor);

        $assignments = $learner !== null ? $this->feed->upcomingForStudent($learner->id, 6) : [];

        return [
            'today' => $agenda['today'],
            'this_week' => $agenda['this_week'],
            'assignments' => $assignments,
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function studentRecentActivity(LmsActor $actor): array
    {
        return $this->studentRecentActivityFor($actor->id);
    }

    /** @return list<array<string, mixed>> */
    protected function studentRecentActivityFor(?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        $views = LmsLearningView::query()
            ->with(['item:id,title,resource_type,activity_type,item_type', 'course:id,name'])
            ->where('user_id', $userId)
            ->orderByDesc('viewed_at')
            ->limit(6)
            ->get()
            ->map(fn (LmsLearningView $view) => [
                'type' => 'viewed',
                'item_id' => $view->learning_item_id !== null ? (int) $view->learning_item_id : null,
                'title' => $view->item?->title,
                'course_id' => (int) $view->course_id,
                'course_name' => $view->course?->name,
                'at' => $view->viewed_at?->toIso8601String(),
            ]);

        $completions = LmsActivityCompletion::query()
            ->with(['item:id,title,resource_type,activity_type', 'course:id,name'])
            ->where('user_id', $userId)
            ->where('status', 'COMPLETE')
            ->orderByDesc('completed_at')
            ->limit(6)
            ->get()
            ->map(fn (LmsActivityCompletion $completion) => [
                'type' => 'completed',
                'item_id' => (int) $completion->learning_item_id,
                'title' => $completion->item?->title,
                'course_id' => (int) $completion->course_id,
                'course_name' => $completion->course?->name,
                'at' => $completion->completed_at?->toIso8601String(),
            ]);

        return collect($views->all())
            ->merge($completions->all())
            ->sortByDesc('at')
            ->take(8)
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    protected function announcementsFor(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        return LmsCourseAnnouncement::query()
            ->with('course:id,name')
            ->whereIn('course_id', $courseIds)
            ->published()
            ->ordered()
            ->limit(5)
            ->get()
            ->map(fn (LmsCourseAnnouncement $announcement) => [
                'id' => (int) $announcement->id,
                'title' => (string) $announcement->title,
                'excerpt' => mb_substr(strip_tags((string) $announcement->content), 0, 160),
                'course_id' => (int) $announcement->course_id,
                'course_name' => $announcement->course?->name,
                'published_at' => $announcement->published_at?->toIso8601String(),
                'pinned' => (bool) $announcement->pinned,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    protected function childSummary(LmsActor $actor, LmsLearner $child): array
    {
        $enrollments = $child->userId !== null
            ? LmsEnrollment::query()
                ->with('progress:id,enrollment_id,progress_percent')
                ->where('user_id', $child->userId)
                ->get()
            : new Collection();

        return [
            'student' => $child->toArray(),
            'courses' => $enrollments->count(),
            'completed_courses' => $enrollments->where('status', EnrollmentStatus::COMPLETED)->count(),
            'average_progress' => $enrollments->isEmpty() ? 0 : round((float) $enrollments->avg('progress_percent'), 2),
            'upcoming_assignments' => $child->id !== null ? $this->feed->upcomingForStudent($child->id, 3) : [],
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function teacherCourseActivity(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        return LmsActivityCompletion::query()
            ->with(['item:id,title', 'course:id,name'])
            ->whereIn('course_id', $courseIds)
            ->where('status', 'COMPLETE')
            ->where('completed_at', '>=', now()->subDays(7))
            ->orderByDesc('completed_at')
            ->limit(10)
            ->get()
            ->map(fn (LmsActivityCompletion $completion) => [
                'item_title' => $completion->item?->title,
                'course_name' => $completion->course?->name,
                'course_id' => (int) $completion->course_id,
                'user_id' => (int) $completion->user_id,
                'at' => $completion->completed_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    protected function enrollmentTrend(): array
    {
        return collect(range(5, 0))->map(function (int $monthsAgo): array {
            $month = now()->subMonths($monthsAgo);

            return [
                'label' => $month->translatedFormat('M Y'),
                'month' => $month->format('Y-m'),
                'total' => (int) LmsEnrollment::query()
                    ->whereYear('enrolled_at', $month->year)
                    ->whereMonth('enrolled_at', $month->month)
                    ->count(),
            ];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    protected function completionTrend(): array
    {
        return collect(range(5, 0))->map(function (int $monthsAgo): array {
            $month = now()->subMonths($monthsAgo);

            return [
                'label' => $month->translatedFormat('M Y'),
                'month' => $month->format('Y-m'),
                'total' => (int) LmsActivityCompletion::query()
                    ->where('status', 'COMPLETE')
                    ->whereYear('completed_at', $month->year)
                    ->whereMonth('completed_at', $month->month)
                    ->count(),
            ];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    protected function mostActiveCourses(int $limit = 5): array
    {
        return LmsCourseProgress::query()
            ->selectRaw('course_id, avg(progress_percent) as avg_progress, count(*) as learners')
            ->groupBy('course_id')
            ->orderByDesc('learners')
            ->limit($limit)
            ->get()
            ->map(function (LmsCourseProgress $row) {
                $course = LmsCourse::query()->find($row->course_id);

                return [
                    'course_id' => (int) $row->course_id,
                    'name' => $course?->name,
                    'learners' => (int) $row->learners,
                    'average_progress' => round((float) $row->avg_progress, 2),
                ];
            })
            ->all();
    }

    /** @return list<array<string, mixed>> */
    protected function inactiveCourses(int $limit = 5): array
    {
        return LmsCourse::query()
            ->where('status', CourseStatus::PUBLISHED->value)
            ->whereDoesntHave('enrollments', fn (Builder $q) => $q->where('last_accessed_at', '>=', now()->subDays(30)))
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (LmsCourse $course) => $this->managementCard($course))
            ->all();
    }

    protected function bookmarkCount(LmsActor $actor): int
    {
        return (int) \App\Modules\LMS\Models\LmsBookmark::query()->where('user_id', $actor->id)->count();
    }

    protected function greeting(): string
    {
        $hour = (int) now()->format('G');

        return match (true) {
            $hour < 11 => 'Selamat pagi',
            $hour < 15 => 'Selamat siang',
            $hour < 18 => 'Selamat sore',
            default => 'Selamat malam',
        };
    }
}
