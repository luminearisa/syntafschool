<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\CourseContentService;
use App\Modules\LMS\Domain\Enums\ContentVisibility;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Http\Resources\LmsSectionResource;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Section/pertemuan di dalam course (§10, §11). */
class LmsSectionController extends LmsController
{
    public function __construct(
        private readonly CourseContentService $content,
        private readonly CourseAccessService $access,
    ) {}

    public function index(Request $request, LmsCourse $course): JsonResponse
    {
        $actor = $this->actor($request);
        $enrollment = $course->enrollmentFor($actor->id);
        $decision = $this->access->decide($course, $actor);

        $this->authorizeLms($decision->allowed, $decision->message ?? 'Anda tidak memiliki akses ke course ini.');

        $sections = $course->sections()->with('items')->get();

        return $this->successResponse(
            LmsSectionResource::collection($sections),
            'Daftar bagian course berhasil diambil',
        );
    }

    public function store(Request $request, LmsCourse $course): JsonResponse
    {
        $this->authorizeLms($this->access->canEditContent($course, $this->actor($request)), 'Anda tidak berhak menambah bagian pada course ini.');

        $section = $this->content->createSection($course, $this->validated($request));

        return $this->createdResponse(new LmsSectionResource($section), 'Bagian course berhasil dibuat');
    }

    public function update(Request $request, LmsCourseSection $section): JsonResponse
    {
        $this->authorizeLms($this->access->canEditContent($section->course, $this->actor($request)), 'Anda tidak berhak mengubah bagian ini.');

        $section = $this->content->updateSection($section, $this->validated($request, updating: true));

        return $this->successResponse(new LmsSectionResource($section), 'Bagian course berhasil diperbarui');
    }

    public function destroy(Request $request, LmsCourseSection $section): JsonResponse
    {
        $this->authorizeLms($this->access->canEditContent($section->course, $this->actor($request)), 'Anda tidak berhak menghapus bagian ini.');

        $this->content->deleteSection($section);

        return $this->successResponse(null, 'Bagian course berhasil dihapus');
    }

    public function duplicate(Request $request, LmsCourseSection $section): JsonResponse
    {
        $this->authorizeLms($this->access->canEditContent($section->course, $this->actor($request)));

        $copy = $this->content->duplicateSection($section);

        return $this->createdResponse(new LmsSectionResource($copy), 'Bagian course berhasil diduplikasi');
    }

    public function reorder(Request $request, LmsCourse $course): JsonResponse
    {
        $this->authorizeLms($this->access->canEditContent($course, $this->actor($request)));

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:lms_course_sections,id'],
        ]);

        $this->content->reorderSections($course, $data['ids']);

        return $this->successResponse(null, 'Urutan bagian course berhasil disimpan');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:200000'],
            'summary' => ['nullable', 'string', 'max:500'],
            'visibility' => ['nullable', Rule::in(ContentVisibility::values())],
            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date', 'after_or_equal:available_from'],
            'availability' => ['nullable', 'array'],
            'completion_rule' => ['nullable', Rule::in(['ALL_ITEMS', 'ANY_ITEM', 'MANUAL'])],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ], [], ['title' => 'judul bagian']);
    }
}
