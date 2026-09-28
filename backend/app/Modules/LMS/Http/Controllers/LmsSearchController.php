<?php

namespace App\Modules\LMS\Http\Controllers;

use App\Modules\LMS\Application\Services\CourseSearchService;
use App\Modules\LMS\Application\Services\LmsCategoryService;
use App\Modules\LMS\Http\LmsController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pencarian global LMS (§31). */
class LmsSearchController extends LmsController
{
    public function __construct(
        private readonly CourseSearchService $search,
        private readonly LmsCategoryService $categories,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'category_id' => ['nullable', 'integer'],
            'level' => ['nullable', 'string', 'max:20'],
            'course_type' => ['nullable', 'string', 'max:20'],
            'teacher_id' => ['nullable', 'integer'],
        ]);

        return $this->successResponse([
            ...$this->search->search(
                (string) ($data['q'] ?? ''),
                $this->actor($request),
                array_filter($data, fn ($value, $key) => $key !== 'q' && $value !== null, ARRAY_FILTER_USE_BOTH),
                $this->perPage($request, 12),
            ),
            'filters' => [
                'categories' => $this->categories->options(true),
                'levels' => \App\Modules\LMS\Domain\Enums\CourseLevel::values(),
                'course_types' => \App\Modules\LMS\Domain\Enums\CourseType::values(),
            ],
        ], 'Hasil pencarian LMS berhasil diambil');
    }

    /** GET /lms/search/suggestions — untuk kotak pencarian di header. */
    public function suggestions(Request $request): JsonResponse
    {
        $term = (string) $request->query('q', '');

        return $this->successResponse(
            $this->search->suggestions($term, $this->actor($request), 6),
            'Saran pencarian berhasil diambil',
        );
    }
}
