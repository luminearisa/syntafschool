<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\InteractiveContentService;
use App\Modules\LMS\Domain\Enums\EnrollmentStatus;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsInteractiveContent;
use App\Modules\LMS\Models\LmsLearningItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Konten interaktif (§7–§12).
 *
 * Guru mengelola konten lewat endpoint `activities/{item}/interactive/*`;
 * siswa hanya menerima payload pemutar tanpa kunci jawaban (§12).
 */
class LmsInteractiveContentController extends LmsController
{
    public function __construct(
        private readonly InteractiveContentService $contents,
        private readonly CourseAccessService $access,
    ) {}

    /** GET /lms/items/{item}/interactive */
    public function show(Request $request, int $item): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->item($item);

        $content = $this->contents->forItem((int) $model->id);

        if ($content === null) {
            abort(404, 'Materi ini tidak memiliki konten interaktif.');
        }

        if ($actor->can('lms.interactive.view') && $this->access->canManage($model->course, $actor)) {
            return $this->successResponse($this->contents->payload($content, forStaff: true));
        }

        $student = $this->studentEnrollment($request, $model);

        $this->authorizeLms(
            $this->access->decide($model->course, $actor, $model)->allowed,
            'Konten interaktif ini belum dapat Anda buka.',
        );

        return $this->successResponse($this->contents->playerPayload($content, (int) $student->user_id));
    }

    /** POST /lms/items/{item}/interactive */
    public function store(Request $request, int $item): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->item($item);
        $this->authorizeManage($model);

        $data = $request->validate([
            'content_type' => ['required', 'string'],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'payload' => ['nullable', 'array'],
            'completion_rule' => ['nullable', 'string'],
            'completion_min_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'completion_min_slides' => ['nullable', 'integer', 'min:0'],
            'required_interactions' => ['nullable', 'integer', 'min:0'],
            'settings' => ['nullable', 'array'],
        ]);

        $content = $this->contents->create($data + ['learning_item_id' => (int) $model->id], $actor);

        return $this->createdResponse($this->contents->payload($content, forStaff: true), 'Konten interaktif dibuat.');
    }

    /** PUT /lms/interactive/{content} */
    public function update(Request $request, int $content): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->content($content);
        $this->authorizeManage($model->learningItem ?? $this->item((int) $model->learning_item_id));

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'payload' => ['nullable', 'array'],
            'completion_rule' => ['nullable', 'string'],
            'completion_min_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'completion_min_slides' => ['nullable', 'integer', 'min:0'],
            'required_interactions' => ['nullable', 'integer', 'min:0'],
            'settings' => ['nullable', 'array'],
        ]);

        return $this->successResponse(
            $this->contents->payload($this->contents->update($model, $data, $actor), forStaff: true),
            'Konten interaktif diperbarui.',
        );
    }

    /** POST /lms/interactive/{content}/publish */
    public function publish(Request $request, int $content): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->content($content);
        $this->authorizeManage($model->learningItem ?? $this->item((int) $model->learning_item_id));

        return $this->successResponse(
            $this->contents->payload($this->contents->publish($model, $actor), forStaff: true),
            'Konten interaktif diterbitkan.',
        );
    }

    /** POST /lms/interactive/{content}/duplicate */
    public function duplicate(Request $request, int $content): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->content($content);
        $this->authorizeManage($model->learningItem ?? $this->item((int) $model->learning_item_id));

        return $this->createdResponse(
            $this->contents->payload($this->contents->duplicate($model, $actor), forStaff: true),
            'Konten interaktif diduplikasi.',
        );
    }

    /** DELETE /lms/interactive/{content} */
    public function destroy(Request $request, int $content): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->content($content);
        $this->authorizeManage($model->learningItem ?? $this->item((int) $model->learning_item_id));

        $this->contents->delete($model, $actor);

        return $this->successResponse(null, 'Konten interaktif dihapus.');
    }

    /** GET /lms/interactive/{content}/versions */
    public function versions(Request $request, int $content): JsonResponse
    {
        $model = $this->content($content);
        $this->authorizeManage($model->learningItem ?? $this->item((int) $model->learning_item_id));

        return $this->successResponse(['versions' => $this->contents->versions($model)]);
    }

    /** POST /lms/interactive/{content}/respond */
    public function respond(Request $request, int $content): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->content($content);

        $data = $request->validate([
            'responses' => ['required', 'array'],
            'responses.*' => ['array'],
            'slide_index' => ['nullable', 'integer', 'min:0'],
        ]);

        $item = $model->learningItem ?? $this->item((int) $model->learning_item_id);
        $enrollment = $this->studentEnrollment($request, $item, requireAccess: true);

        return $this->successResponse(
            $this->contents->respond($model, (int) $enrollment->user_id, $data, $actor),
            'Jawaban tersimpan.',
        );
    }

    /** GET /lms/interactive/{content}/progress */
    public function progress(Request $request, int $content): JsonResponse
    {
        $model = $this->content($content);
        $item = $model->learningItem ?? $this->item((int) $model->learning_item_id);
        $enrollment = $this->studentEnrollment($request, $item, requireAccess: true);

        return $this->successResponse($this->contents->progress($model, $enrollment));
    }

    /** GET /lms/courses/{course}/interactive */
    public function index(Request $request, int $course): JsonResponse
    {
        $actor = $this->actor($request);
        $model = LmsCourse::query()->find($course);
        abort_if($model === null, 404, 'Course tidak ditemukan.');

        $this->authorizeLms(
            $this->access->canManage($model, $actor) || $model->enrollmentFor($actor->id) !== null || (bool) $actor->can('lms.interactive.view'),
            'Anda tidak memiliki akses ke course ini.',
        );

        return $this->successResponse(['contents' => $this->contents->forCourse((int) $model->id)]);
    }

    private function item(int $id): LmsLearningItem
    {
        $item = LmsLearningItem::query()->with('course')->find($id);
        abort_if($item === null, 404, 'Materi tidak ditemukan.');

        return $item;
    }

    private function content(int $id): LmsInteractiveContent
    {
        $content = LmsInteractiveContent::query()->find($id);
        abort_if($content === null, 404, 'Konten interaktif tidak ditemukan.');

        return $content;
    }

    private function authorizeManage(LmsLearningItem $item): void
    {
        $actor = $this->actor(request());

        $this->authorizeLms(
            $actor->can('lms.interactive.manage') && $this->access->canManage($item->course, $actor),
            'Anda tidak berhak mengelola konten interaktif course ini.',
        );
    }

    private function studentEnrollment(Request $request, LmsLearningItem $item, bool $requireAccess = false): LmsEnrollment
    {
        $actor = $this->actor($request);
        $enrollment = $item->course->enrollmentFor($actor->id);

        if ($enrollment === null || $enrollment->status !== EnrollmentStatus::ACTIVE) {
            LmsFault::forbidden('Anda belum terdaftar sebagai peserta pada course ini.');
        }

        if ($requireAccess && ! $this->access->decide($item->course, $actor, $item)->allowed) {
            LmsFault::forbidden('Materi ini belum dapat Anda kerjakan.');
        }

        return $enrollment;
    }
}
