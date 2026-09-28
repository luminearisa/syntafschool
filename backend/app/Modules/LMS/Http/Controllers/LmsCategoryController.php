<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\LmsCategoryService;
use App\Modules\LMS\Domain\Enums\CategoryStatus;
use App\Modules\LMS\Domain\Enums\CourseVisibility;
use App\Modules\LMS\Http\LmsController;
use App\Modules\LMS\Http\Resources\LmsCategoryResource;
use App\Modules\LMS\Models\LmsCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Kategori & subkategori (§4). */
class LmsCategoryController extends LmsController
{
    public function __construct(private readonly LmsCategoryService $categories) {}

    public function index(Request $request): JsonResponse
    {
        $onlyVisible = $request->boolean('visible_only', ! $this->actor($request)->can('lms.manage'));

        $tree = $this->categories->tree($onlyVisible);

        return $this->successResponse([
            'tree' => $tree,
            'options' => $this->categories->options($onlyVisible),
        ], 'Daftar kategori LMS berhasil diambil');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'parent_id' => ['nullable', 'integer', 'exists:lms_categories,id'],
            'slug' => ['nullable', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', Rule::in(CategoryStatus::values())],
            'visibility' => ['nullable', Rule::in(CourseVisibility::values())],
        ], [], ['name' => 'nama kategori']);

        $category = $this->categories->create($data, $this->actor($request));

        return $this->createdResponse(new LmsCategoryResource($category), 'Kategori LMS berhasil dibuat');
    }

    public function update(Request $request, LmsCategory $category): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'parent_id' => ['nullable', 'integer', 'exists:lms_categories,id'],
            'slug' => ['nullable', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', Rule::in(CategoryStatus::values())],
            'visibility' => ['nullable', Rule::in(CourseVisibility::values())],
        ]);

        $category = $this->categories->update($category, $data);

        return $this->successResponse(new LmsCategoryResource($category), 'Kategori LMS berhasil diperbarui');
    }

    public function destroy(LmsCategory $category): JsonResponse
    {
        $this->categories->delete($category);

        return $this->successResponse(null, 'Kategori LMS berhasil dihapus');
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:lms_categories,id'],
            'parent_id' => ['nullable', 'integer', 'exists:lms_categories,id'],
        ]);

        $this->categories->reorder($data['ids'], $data['parent_id'] ?? null);

        return $this->successResponse(null, 'Urutan kategori berhasil disimpan');
    }
}
