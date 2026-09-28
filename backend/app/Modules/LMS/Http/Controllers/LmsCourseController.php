<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\CourseAnnouncementService;
use App\Modules\LMS\Application\Services\CourseContentService;
use App\Modules\LMS\Application\Services\CourseEnrollmentService;
use App\Modules\LMS\Application\Services\CourseProgressService;
use App\Modules\LMS\Application\Services\CoursePublishService;
use App\Modules\LMS\Application\Services\CourseService;
use App\Modules\LMS\Application\Services\LmsCategoryService;
use App\Modules\LMS\Domain\Enums\ActivityType;
use App\Modules\LMS\Domain\Enums\CompletionType;
use App\Modules\LMS\Domain\Enums\CourseLevel;
use App\Modules\LMS\Domain\Enums\CourseScope;
use App\Modules\LMS\Domain\Enums\CourseStatus;
use App\Modules\LMS\Domain\Enums\CourseType;
use App\Modules\LMS\Domain\Enums\CourseVisibility;
use App\Modules\LMS\Domain\Enums\ItemType;
use App\Modules\LMS\Domain\Enums\ResourceType;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Http\Resources\LmsAnnouncementResource;
use App\Modules\LMS\Http\Resources\LmsCourseResource;
use App\Modules\LMS\Http\Resources\LmsGroupResource;
use App\Modules\LMS\Models\LmsCourse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Course: daftar, pembuatan, pengubahan, penerbitan (§5–§9, §37). */
class LmsCourseController extends LmsController
{
    public function __construct(
        private readonly CourseService $courses,
        private readonly CoursePublishService $publisher,
        private readonly CourseContentService $content,
        private readonly CourseProgressService $progress,
        private readonly CourseAccessService $access,
        private readonly CourseEnrollmentService $enrollments,
        private readonly CourseAnnouncementService $announcements,
        private readonly LmsCategoryService $categories,
    ) {}

    public function lookups(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->courses->lookups($this->actor($request), $this->categories),
            'Referensi LMS berhasil diambil',
        );
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $scope = CourseScope::tryFrom((string) $request->query('scope', ''))
            ?? ($request->boolean('mine') ? CourseScope::MANAGED : CourseScope::ENROLLED);

        if (! $actor->canAny(['lms.course.view', 'lms.manage'])) {
            $scope = CourseScope::PUBLIC;
        }

        $query = $this->courses->query($actor, $scope, $request->only([
            'search', 'category_id', 'status', 'visibility', 'course_type', 'level',
            'teacher_id', 'academic_year_id', 'class_id', 'sort', 'direction',
        ]) + ['featured' => $request->boolean('featured')]);

        if ($request->boolean('all')) {
            return $this->successResponse(
                LmsCourseResource::collection($query->limit(200)->get()),
                'Daftar course LMS berhasil diambil',
            );
        }

        return $this->successResponse(
            LmsCourseResource::collection($query->paginate($this->perPage($request))->appends($request->query())),
            'Daftar course LMS berhasil diambil',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $course = $this->courses->create($this->actor($request), $data);

        return $this->createdResponse(
            new LmsCourseResource($course->load(['category', 'teachers'])),
            'Course LMS berhasil dibuat sebagai draf',
        );
    }

    public function show(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $decision = $this->access->decide($course, $actor);

        if (! $decision->allowed && ! $this->access->canManage($course, $actor)) {
            return $this->errorResponse($decision->message ?? 'Anda tidak memiliki akses ke course ini.', 403);
        }

        $manages = $this->access->canManage($course, $actor);
        $enrollment = $course->enrollmentFor($actor->id);

        $course->load(['category', 'teachers', 'groups.members']);

        return $this->successResponse([
            'course' => new LmsCourseResource($course),
            'statistics' => $manages ? $this->courses->statistics($course) : null,
            'access' => $decision->toArray(),
            'enrollment' => $enrollment !== null ? [
                'id' => (int) $enrollment->id,
                'role' => $enrollment->role?->value,
                'status' => $enrollment->status?->value,
                'progress_percent' => (float) $enrollment->progress_percent,
            ] : null,
            'capabilities' => [
                'manage' => $manages,
                'edit_content' => $this->access->canEditContent($course, $actor),
                'grade' => $this->access->canGrade($course, $actor),
                'learn' => $enrollment !== null && $enrollment->isActive(),
                'read_only' => $actor->isParent(),
            ],
            'structure' => $this->content->structure($course, $actor, $enrollment, $this->access),
            'progress' => $this->progress->summary($course, $actor),
            'announcements' => LmsAnnouncementResource::collection(
                $this->announcements->forCourse($course, ! $manages)->limit(10)->get(),
            ),
            'groups' => $manages ? LmsGroupResource::collection($course->groups) : [],
            'academic' => $this->courses->academicSummary($course),
            'resume' => $this->courses->resume($course, $actor),
        ], 'Detail course LMS berhasil diambil');
    }

    public function update(Request $request, LmsCourse $course): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($course, $this->actor($request)));

        $course = $this->courses->update($course, $this->validated($request, updating: true), $this->actor($request));

        return $this->successResponse(
            new LmsCourseResource($course->load(['category', 'teachers'])),
            'Course LMS berhasil diperbarui',
        );
    }

    public function destroy(Request $request, LmsCourse $course): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($course, $this->actor($request)));

        $this->courses->delete($course);

        return $this->successResponse(null, 'Course LMS berhasil dihapus');
    }

    public function duplicate(Request $request, LmsCourse $course): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($course, $this->actor($request)));

        $data = $request->validate(['with_content' => ['nullable', 'boolean']]);
        $copy = $this->courses->duplicate($course, $this->actor($request), (bool) ($data['with_content'] ?? true));

        return $this->createdResponse(new LmsCourseResource($copy), 'Course LMS berhasil diduplikasi');
    }

    public function readiness(Request $request, LmsCourse $course): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($course, $this->actor($request)));

        return $this->successResponse($this->publisher->readiness($course), 'Kesiapan penerbitan course berhasil diperiksa');
    }

    public function publish(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($this->access->canManage($course, $actor), 'Anda tidak berhak menerbitkan course ini.');

        $data = $request->validate(['force' => ['nullable', 'boolean']]);
        $course = $this->publisher->publish($course, $actor, (bool) ($data['force'] ?? false));

        return $this->successResponse(new LmsCourseResource($course), 'Course LMS berhasil diterbitkan');
    }

    public function unpublish(Request $request, LmsCourse $course): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($course, $this->actor($request)));

        return $this->successResponse(
            new LmsCourseResource($this->publisher->unpublish($course, $this->actor($request))),
            'Course LMS dikembalikan menjadi draf',
        );
    }

    public function archive(Request $request, LmsCourse $course): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($course, $this->actor($request)));

        return $this->successResponse(
            new LmsCourseResource($this->publisher->archive($course, $this->actor($request))),
            'Course LMS berhasil diarsipkan',
        );
    }

    public function restore(Request $request, LmsCourse $course): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($course, $this->actor($request)));

        return $this->successResponse(
            new LmsCourseResource($this->publisher->restore($course, $this->actor($request))),
            'Course LMS berhasil dipulihkan sebagai draf',
        );
    }

    /** Statistik ringkas untuk tab analitik di halaman course (§27, §29). */
    public function statistics(Request $request, LmsCourse $course): JsonResponse
    {
        $this->authorizeLms($this->access->canManage($course, $this->actor($request)));

        return $this->successResponse([
            'statistics' => $this->courses->statistics($course),
            'enrollments' => $this->enrollments->summary($course),
            'readiness' => $this->publisher->readiness($course),
        ], 'Statistik course berhasil diambil');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:180'],
            'category_id' => ['nullable', 'integer', 'exists:lms_categories,id'],
            'academic_course_id' => ['nullable', 'integer'],
            'academic_year_id' => ['nullable', 'integer'],
            'semester_id' => ['nullable', 'integer'],
            'program_id' => ['nullable', 'integer'],
            'class_id' => ['nullable', 'integer'],
            'teacher_id' => ['nullable', 'integer'],
            'teachers' => ['nullable', 'array'],
            'teachers.*.teacher_id' => ['required_with:teachers', 'integer'],
            'teachers.*.is_primary' => ['nullable', 'boolean'],
            'code' => ['nullable', 'string', 'max:60'],
            'slug' => ['nullable', 'string', 'max:200'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:200000'],
            'learning_objectives' => ['nullable', 'string', 'max:10000'],
            'thumbnail' => ['nullable', 'string', 'max:255'],
            'cover_image' => ['nullable', 'string', 'max:255'],
            'course_type' => ['nullable', Rule::in(CourseType::values())],
            'level' => ['nullable', Rule::in(CourseLevel::values())],
            'language' => ['nullable', 'string', 'max:10'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'visibility' => ['nullable', Rule::in(CourseVisibility::values())],
            'is_featured' => ['nullable', 'boolean'],
            'max_students' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'sync_academic_enrollments' => ['nullable', 'boolean'],
            'status' => $updating ? ['nullable', Rule::in(CourseStatus::values())] : ['excluded'],
            'content_types' => ['nullable', 'array'],
            'content_types.*' => [Rule::in([...ItemType::values(), ...ResourceType::values(), ...ActivityType::values(), ...CompletionType::values()])],
        ], [], [
            'name' => 'nama course',
            'category_id' => 'kategori',
            'academic_course_id' => 'mata pelajaran SIAKAD',
            'class_id' => 'rombel',
        ]);
    }
}
