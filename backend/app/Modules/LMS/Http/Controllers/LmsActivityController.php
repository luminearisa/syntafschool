<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\ActivityAvailabilityService;
use App\Modules\LMS\Application\Services\ActivityService;
use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Domain\Enums\ActivityType;
use App\Modules\LMS\Http\Controllers\Concerns\ResolvesActivities;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Http\Resources\LmsActivityResource;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aktivitas pembelajaran (§2–§3, §42–§45, §53).
 */
class LmsActivityController extends LmsController
{
    use ResolvesActivities;

    public function __construct(
        private readonly ActivityService $activities,
        private readonly ActivityAvailabilityService $availability,
        private readonly CourseAccessService $access,
    ) {}

    /** Daftar aktivitas sebuah course (guru & siswa). */
    public function index(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $enrollment = $course->enrollmentFor($actor->id);
        $decision = $this->access->decide($course, $actor);

        $this->authorizeLms($decision->allowed, $decision->message ?? 'Anda tidak memiliki akses ke course ini.');

        $filters = $request->only(['type', 'status', 'section_id', 'search']);

        if (! $this->access->canManage($course, $actor)) {
            $filters['status'] = 'PUBLISHED';
        }

        return $this->successResponse(
            LmsActivityResource::collection($this->activities->forCourse($course, $filters, $this->perPage($request))),
            'Daftar aktivitas berhasil diambil',
        );
    }

    /** Tipe aktivitas yang tersedia + ringkasan untuk wizard (§43). */
    public function types(Request $request): JsonResponse
    {
        $types = array_values(array_filter(
            ActivityType::cases(),
            fn (ActivityType $type) => $type !== ActivityType::WORKSHOP,
        ));

        return $this->successResponse([
            'types' => array_map(fn (ActivityType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'icon' => $type->icon(),
                'graded' => $type->isGraded(),
            ], $types),
            'later' => [[
                'value' => ActivityType::WORKSHOP->value,
                'label' => ActivityType::WORKSHOP->label(),
                'note' => 'Disiapkan untuk phase berikutnya.',
            ]],
        ], 'Daftar tipe aktivitas berhasil diambil');
    }

    /** Buat aktivitas melalui wizard (§43). */
    public function store(Request $request, LmsCourseSection $section): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeLms($this->access->canManage($section->course, $actor));

        $data = $this->validatePayload($request, [
            'activity_type' => ['required', 'string', 'in:'.implode(',', ActivityType::values())],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'instructions' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:DRAFT,PUBLISHED,ARCHIVED'],
            'start_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'close_at' => ['nullable', 'date', 'after_or_equal:due_at'],
            'visibility' => ['nullable', 'string', 'in:VISIBLE,HIDDEN'],
            'is_required' => ['nullable', 'boolean'],
            'completion_type' => ['nullable', 'string'],
            'completion_min_grade' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_preview' => ['nullable', 'boolean'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1'],
            'availability' => ['nullable', 'array'],
            'required_item_ids' => ['nullable', 'array'],
            'required_grade' => ['nullable', 'numeric'],
            'require_previous_item' => ['nullable', 'boolean'],
        ]);

        $activity = $this->activities->create($section, $data, $actor);

        return $this->createdResponse(new LmsActivityResource($activity), 'Aktivitas berhasil dibuat sebagai draf');
    }

    /** Detail aktivitas + status untuk pelaku yang membuka (§42). */
    public function show(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $actor = $this->actor($request);
        $course = $model->course;

        $this->authorizeLms($course !== null && $this->access->decide($course, $actor, $model->learningItem)->allowed);

        $isStaff = $this->access->canManage($course, $actor) || $this->access->canGrade($course, $actor);

        return $this->successResponse([
            'activity' => new LmsActivityResource($model),
            'state' => $this->availability->studentState($model, $course?->enrollmentFor($actor->id), $isStaff),
            'neighbours' => $this->availability->neighbours($model),
            'can_manage' => $isStaff,
            'can_grade' => $course !== null && $this->access->canGrade($course, $actor),
        ], 'Detail aktivitas berhasil diambil');
    }

    /** Ubah konfigurasi aktivitas (tab wizard lain ikut lewat sini) (§43). */
    public function update(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $actor = $this->authorizeManage($request, $model);

        $data = $this->validatePayload($request, [
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'instructions' => ['nullable', 'string'],
            'start_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'close_at' => ['nullable', 'date'],
            'visibility' => ['nullable', 'string', 'in:VISIBLE,HIDDEN'],
            'completion_rule' => ['nullable', 'string'],
            'completion_min_grade' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'availability' => ['nullable', 'array'],
            'is_graded' => ['nullable', 'boolean'],
            'is_required' => ['nullable', 'boolean'],
            'is_preview' => ['nullable', 'boolean'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->successResponse(
            new LmsActivityResource($this->activities->update($model, $data, $actor)),
            'Aktivitas berhasil diperbarui',
        );
    }

    public function destroy(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $this->authorizeManage($request, $model);

        $this->activities->delete($model);

        return $this->successResponse(null, 'Aktivitas berhasil dihapus');
    }

    public function publish(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $actor = $this->authorizeManage($request, $model);

        return $this->successResponse(
            new LmsActivityResource($this->activities->publish($model, $actor)),
            'Aktivitas berhasil diterbitkan',
        );
    }

    public function unpublish(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $actor = $this->authorizeManage($request, $model);

        return $this->successResponse(
            new LmsActivityResource($this->activities->unpublish($model, $actor)),
            'Aktivitas dikembalikan ke draf',
        );
    }

    public function archive(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $actor = $this->authorizeManage($request, $model);

        return $this->successResponse(
            new LmsActivityResource($this->activities->archive($model, $actor)),
            'Aktivitas berhasil diarsipkan',
        );
    }

    /** Duplikasi aktivitas tanpa riwayat (§44). */
    public function duplicate(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $actor = $this->authorizeManage($request, $model);

        $copy = $this->activities->duplicate($model, $actor, $request->input('title'));

        return $this->createdResponse(new LmsActivityResource($copy), 'Aktivitas berhasil diduplikasi');
    }

    /** Pratinjau sebagai siswa — tanpa enrollment palsu (§45). */
    public function preview(Request $request, int $activity): JsonResponse
    {
        $model = $this->activity($activity);
        $actor = $this->authorizeManage($request, $model);

        $this->authorizeLms($actor->can('lms.activity.preview') || $actor->can('lms.manage'));

        return $this->successResponse([
            'activity' => new LmsActivityResource($model),
            'preview' => $this->activities->previewPayload($model),
        ], 'Mode pratinjau aktif');
    }
}
