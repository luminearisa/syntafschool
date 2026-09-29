<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseAccessService;
use App\Modules\LMS\Application\Services\CourseContentService;
use App\Modules\LMS\Domain\Enums\ActivityType;
use App\Modules\LMS\Domain\Enums\CompletionType;
use App\Modules\LMS\Domain\Enums\ContentVisibility;
use App\Modules\LMS\Domain\Enums\ItemType;
use App\Modules\LMS\Domain\Enums\ResourceType;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Http\Resources\LmsLearningItemResource;
use App\Modules\LMS\Models\LmsCourseSection;
use App\Modules\LMS\Models\LmsLearningItem;
use App\Modules\LMS\Models\LmsResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Learning item: materi & aktivitas (§12–§14, §40). */
class LmsLearningItemController extends LmsController
{
    public function __construct(
        private readonly CourseContentService $content,
        private readonly CourseAccessService $access,
    ) {}

    public function store(Request $request, LmsCourseSection $section): JsonResponse
    {
        $course = $section->course;
        $this->authorizeLms($this->access->canEditContent($course, $this->actor($request)), 'Anda tidak berhak menambah materi pada course ini.');

        $item = $this->content->createItem($section, $this->validated($request), $this->actor($request));

        return $this->createdResponse(
            new LmsLearningItemResource($item->load(['resource', 'chapters'])),
            'Materi/aktivitas berhasil ditambahkan',
        );
    }

    public function show(Request $request, LmsLearningItem $item): JsonResponse
    {
        $actor = $this->actor($request);
        $decision = $this->access->decide($item->course, $actor, $item);

        $this->authorizeLms($decision->allowed, $decision->message ?? 'Materi ini terkunci.');

        $item->load(['resource', 'chapters', 'children']);

        return $this->successResponse([
            'item' => new LmsLearningItemResource($item),
            'access' => $decision->toArray(),
            'navigation' => [
                'previous' => $this->content->navigation($item, 'previous'),
                'next' => $this->content->navigation($item, 'next'),
            ],
        ], 'Detail materi berhasil diambil');
    }

    public function update(Request $request, LmsLearningItem $item): JsonResponse
    {
        $this->authorizeLms($this->access->canEditContent($item->course, $this->actor($request)), 'Anda tidak berhak mengubah materi ini.');

        $item = $this->content->updateItem($item, $this->validated($request, updating: true));

        return $this->successResponse(
            new LmsLearningItemResource($item->load(['resource', 'chapters'])),
            'Materi/aktivitas berhasil diperbarui',
        );
    }

    public function destroy(Request $request, LmsLearningItem $item): JsonResponse
    {
        $this->authorizeLms($this->access->canEditContent($item->course, $this->actor($request)), 'Anda tidak berhak menghapus materi ini.');

        $this->content->deleteItem($item);

        return $this->successResponse(null, 'Materi/aktivitas berhasil dihapus');
    }

    public function duplicate(Request $request, LmsLearningItem $item): JsonResponse
    {
        $this->authorizeLms($this->access->canEditContent($item->course, $this->actor($request)));

        $copy = $this->content->duplicateItem($item);

        return $this->createdResponse(new LmsLearningItemResource($copy), 'Materi berhasil diduplikasi');
    }

    public function move(Request $request, LmsLearningItem $item): JsonResponse
    {
        $this->authorizeLms($this->access->canEditContent($item->course, $this->actor($request)));

        $data = $request->validate(['section_id' => ['required', 'integer', 'exists:lms_course_sections,id']]);
        $section = LmsCourseSection::query()->findOrFail($data['section_id']);

        return $this->successResponse(
            new LmsLearningItemResource($this->content->moveItem($item, $section)),
            'Materi berhasil dipindahkan',
        );
    }

    public function reorder(Request $request, LmsCourseSection $section): JsonResponse
    {
        $this->authorizeLms($this->access->canEditContent($section->course, $this->actor($request)));

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $this->content->reorderItems($section, $data['ids']);

        return $this->successResponse(null, 'Urutan materi berhasil disimpan');
    }

    /** Unggah berkas materi — disimpan di disk Laravel, bukan database (§34). */
    public function upload(Request $request, LmsLearningItem $item): JsonResponse
    {
        $this->authorizeLms($this->access->canEditContent($item->course, $this->actor($request)));

        $maxKb = (int) config('lms.uploads.max_size_kb', 20480);
        $mimes = implode(',', (array) config('lms.uploads.allowed_mimes', []));

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKb, 'mimes:'.$mimes],
        ], [], ['file' => 'berkas materi']);

        $resource = $this->content->storeUpload($item, $data['file']);

        return $this->createdResponse([
            'resource' => [
                'id' => (int) $resource->id,
                'file_name' => $resource->file_name,
                'file_size' => $resource->file_size !== null ? (int) $resource->file_size : null,
                'file_mime' => $resource->file_mime,
                'download_url' => '/api/v1/lms/resources/'.$resource->id.'/download',
            ],
        ], 'Berkas materi berhasil diunggah');
    }

    /** Unduhan ber-otorisasi: berkas privat tidak pernah disajikan langsung (§34). */
    public function download(Request $request, LmsResource $resource): StreamedResponse|JsonResponse
    {
        $item = $resource->item;

        if ($item === null) {
            return $this->errorResponse('Materi tidak ditemukan.', 404);
        }

        $decision = $this->access->decide($item->course, $this->actor($request), $item);

        if (! $decision->allowed) {
            return $this->errorResponse($decision->message ?? 'Anda tidak berhak mengunduh berkas ini.', 403);
        }

        $disk = (string) config('lms.uploads.disk', 'local');

        if ($resource->file_path === null || ! Storage::disk($disk)->exists($resource->file_path)) {
            return $this->errorResponse('Berkas tidak ditemukan pada penyimpanan.', 404);
        }

        return Storage::disk($disk)->download($resource->file_path, $resource->file_name ?? basename($resource->file_path));
    }

    /** Bab buku (§14). */
    public function chapters(Request $request, LmsLearningItem $item): JsonResponse
    {
        $decision = $this->access->decide($item->course, $this->actor($request), $item);
        $this->authorizeLms($decision->allowed, $decision->message ?? 'Materi ini terkunci.');

        return $this->successResponse(
            $item->chapters->map(fn ($chapter) => [
                'id' => (int) $chapter->id,
                'title' => (string) $chapter->title,
                'content' => $chapter->content,
                'sort_order' => (int) $chapter->sort_order,
            ])->values()->all(),
            'Daftar bab berhasil diambil',
        );
    }

    public function saveChapters(Request $request, LmsLearningItem $item): JsonResponse
    {
        $this->authorizeLms($this->access->canEditContent($item->course, $this->actor($request)));

        $data = $request->validate([
            'chapters' => ['required', 'array'],
            'chapters.*.id' => ['nullable', 'integer'],
            'chapters.*.title' => ['required', 'string', 'max:200'],
            'chapters.*.content' => ['nullable', 'string', 'max:200000'],
        ]);

        $this->content->saveChapters($item, $data['chapters']);

        return $this->successResponse(
            $item->fresh('chapters')->chapters->map(fn ($chapter) => [
                'id' => (int) $chapter->id,
                'title' => (string) $chapter->title,
                'sort_order' => (int) $chapter->sort_order,
            ])->values()->all(),
            'Bab buku berhasil disimpan',
        );
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:200000'],
            'item_type' => [$updating ? 'sometimes' : 'required', Rule::in(ItemType::values())],
            'resource_type' => ['nullable', Rule::in(ResourceType::values())],
            'activity_type' => ['nullable', Rule::in(ActivityType::values())],
            'parent_id' => ['nullable', 'integer', 'exists:lms_learning_items,id'],
            'visibility' => ['nullable', Rule::in(ContentVisibility::values())],
            'is_required' => ['nullable', 'boolean'],
            'completion_type' => ['nullable', Rule::in(CompletionType::values())],
            'completion_min_grade' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_preview' => ['nullable', 'boolean'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'content' => ['nullable', 'string', 'max:200000'],
            'external_url' => ['nullable', 'url', 'max:1000'],
            'open_in' => ['nullable', Rule::in(['NEW_TAB', 'SAME_TAB', 'EMBED'])],
            'video_provider' => ['nullable', 'string', 'max:40'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'file_path' => ['nullable', 'string', 'max:255'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'file_mime' => ['nullable', 'string', 'max:150'],
            'file_size' => ['nullable', 'integer', 'min:0'],
            'chapters' => ['nullable', 'array'],
            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date'],
            'require_previous_item' => ['nullable', 'boolean'],
            'required_item_ids' => ['nullable', 'array'],
            'required_section_ids' => ['nullable', 'array'],
            'required_grade' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'required_completion' => ['nullable', 'boolean'],
        ], [], ['title' => 'judul materi']);
    }
}
