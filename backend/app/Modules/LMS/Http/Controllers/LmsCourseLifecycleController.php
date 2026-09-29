<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\CourseArchiveService;
use App\Modules\LMS\Application\Services\CourseCopyService;
use App\Modules\LMS\Application\Services\CoursePrerequisiteService;
use App\Modules\LMS\Application\Services\CoursePublishService;
use App\Modules\LMS\Application\Services\CourseTemplateService;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseArchive;
use App\Modules\LMS\Models\LmsCourseTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Siklus hidup course: template, salin, arsip, prasyarat, dan validasi
 * (§16, §17–§18, §46–§51).
 */
class LmsCourseLifecycleController extends LmsController
{
    public function __construct(
        private readonly CourseTemplateService $templates,
        private readonly CourseCopyService $copies,
        private readonly CourseArchiveService $archives,
        private readonly CoursePrerequisiteService $prerequisites,
        private readonly CoursePublishService $publisher,
        private readonly CourseAccessService $access,
    ) {}

    /** GET /lms/course-templates */
    public function templates(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.template.view') || $actor->can('lms.manage'), 'Anda tidak berhak melihat template course.');

        return $this->successResponse(['templates' => $this->templates->templates($request->only('search', 'category'))]);
    }

    /** POST /lms/courses/{course}/templates */
    public function storeTemplate(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);
        $this->authorizeManage($model);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:120'],
            'include_content' => ['nullable', 'boolean'],
            'include_files' => ['nullable', 'boolean'],
            'include_activities' => ['nullable', 'boolean'],
        ]);

        $template = $this->templates->createFromCourse($model, $data, $actor, $data);

        return $this->createdResponse($this->templates->preview($template), 'Template course dibuat.');
    }

    /** GET /lms/course-templates/{template} */
    public function showTemplate(Request $request, int $template): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.template.view') || $actor->can('lms.manage'), 'Anda tidak berhak melihat template course.');

        return $this->successResponse($this->templates->preview($this->template($template)));
    }

    /** POST /lms/course-templates/{template}/apply */
    public function applyTemplate(Request $request, int $template): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->template($template);

        $data = $request->validate([
            'course_id' => ['required', 'integer'],
            'mode' => ['nullable', 'string', 'in:APPEND,REPLACE'],
        ]);

        $target = $this->course((int) $data['course_id']);
        $this->authorizeManage($target);

        return $this->successResponse(
            $this->templates->apply($model, $target, ['mode' => $data['mode'] ?? 'APPEND'], $actor),
            'Template diterapkan.',
        );
    }

    /** POST /lms/course-templates/{template}/create-course */
    public function createFromTemplate(Request $request, int $template): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.course.create') || $actor->can('lms.manage'), 'Anda tidak berhak membuat course.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'code' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string'],
        ]);

        return $this->createdResponse(
            $this->copies->createCourseFromTemplate($this->template($template), $data, $actor),
            'Course dibuat dari template.',
        );
    }

    /** GET /lms/courses/{course}/copies */
    public function copyHistory(Request $request, int $course): JsonResponse
    {
        $model = $this->course($course);
        $this->authorizeManage($model);

        return $this->successResponse(['copies' => $this->copies->history($model, (int) $this->perPage($request, 20))]);
    }

    /** POST /lms/courses/{course}/copy */
    public function copyContent(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $source = $this->course($course);
        $this->authorizeManage($source);

        $data = $request->validate([
            'target_course_id' => ['required', 'integer'],
            'mode' => ['nullable', 'string', 'in:APPEND,REPLACE'],
            'include_questions' => ['nullable', 'boolean'],
            'include_gradebook' => ['nullable', 'boolean'],
        ]);

        $target = $this->course((int) $data['target_course_id']);
        $this->authorizeManage($target);

        return $this->successResponse(
            $this->copies->copyContent($source, $target, $actor, $data),
            'Isi course disalin.',
        );
    }

    /** POST /lms/courses/{course}/archive */
    public function archive(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);
        $this->authorizeLms($actor->can('lms.course.archive'), 'Anda tidak berhak mengarsipkan course.');

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->successResponse($this->archives->archive($model, (string) $data['reason'], $actor), 'Course diarsipkan.');
    }

    /** GET /lms/courses/{course}/archives */
    public function archiveHistory(Request $request, int $course): JsonResponse
    {
        $model = $this->course($course);
        $this->authorizeLms($this->actor($request)->can('lms.report.view') || $this->actor($request)->can('lms.manage'), 'Anda tidak berhak melihat riwayat arsip.');

        return $this->successResponse([
            'archives' => $this->archives->history($model, (int) $this->perPage($request, 20)),
            'active' => $this->archives->active(),
        ]);
    }

    /** POST /lms/course-archives/{archive}/restore */
    public function restore(Request $request, int $archive): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($actor->can('lms.course.archive'), 'Anda tidak berhak memulihkan course.');

        $model = LmsCourseArchive::query()->find($archive);
        abort_if($model === null, 404, 'Arsip tidak ditemukan.');

        return $this->successResponse($this->archives->restore($model, $actor), 'Course dipulihkan.');
    }

    /** GET /lms/courses/{course}/prerequisites */
    public function prerequisites(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);

        $this->authorizeLms(
            $this->access->canManage($model, $actor) || $model->enrollmentFor($actor->id) !== null,
            'Anda tidak memiliki akses ke prasyarat course ini.',
        );

        $staff = $this->access->canManage($model, $actor);

        return $this->successResponse([
            'requirements' => $staff ? $model->prerequisites()->with('prerequisite:id,name,code')->get() : [],
            'status' => $staff ? null : $this->prerequisites->statusFor($model, $actor),
        ]);
    }

    /** POST /lms/courses/{course}/prerequisites */
    public function attachPrerequisite(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);
        $this->authorizeManage($model);

        $data = $request->validate([
            'prerequisite_course_id' => ['required', 'integer'],
            'is_enforced' => ['nullable', 'boolean'],
            'minimum_progress' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $result = $this->prerequisites->attach(
            $model,
            $this->course((int) $data['prerequisite_course_id']),
            $actor,
            (bool) ($data['is_enforced'] ?? true),
            (float) ($data['minimum_progress'] ?? 100),
        );

        if (! $result['attached']) {
            return $this->errorResponse($result['message'] ?? 'Prasyarat membentuk siklus.', 422, ['cycle' => $result['cycle']]);
        }

        return $this->createdResponse($result['rule'], 'Prasyarat ditetapkan.');
    }

    /** DELETE /lms/courses/{course}/prerequisites/{prerequisite} */
    public function detachPrerequisite(Request $request, int $course, int $prerequisite): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->course($course);
        $this->authorizeManage($model);

        $removed = $this->prerequisites->detach($model, $this->course($prerequisite), $actor);

        return $this->successResponse(['removed' => $removed], 'Prasyarat dihapus.');
    }

    /** GET /lms/courses/{course}/validation */
    public function validation(Request $request, int $course): JsonResponse
    {
        $model = $this->course($course);
        $this->authorizeManage($model);

        $readiness = $this->publisher->readiness($model);

        return $this->successResponse($readiness);
    }

    private function course(int $id): LmsCourse
    {
        $course = LmsCourse::query()->find($id);
        abort_if($course === null, 404, 'Course tidak ditemukan.');

        return $course;
    }

    private function template(int $id): LmsCourseTemplate
    {
        $template = LmsCourseTemplate::query()->find($id);
        abort_if($template === null, 404, 'Template tidak ditemukan.');

        return $template;
    }

    private function authorizeManage(LmsCourse $course): void
    {
        $actor = $this->actor(request());

        $this->authorizeLms(
            $this->access->canManage($course, $actor) && ($actor->can('lms.template.manage') || $actor->can('lms.course.copy') || $actor->can('lms.manage') || $actor->can('lms.course.prerequisite.manage')),
            'Anda tidak berhak mengelola course ini.',
        );
    }
}
