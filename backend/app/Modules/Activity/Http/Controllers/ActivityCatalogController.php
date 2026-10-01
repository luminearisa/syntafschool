<?php

namespace App\Modules\Activity\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Modules\Activity\Application\Services\ActivityCategoryService;
use App\Modules\Activity\Application\Services\ActivityPeriodService;
use App\Modules\Activity\Application\Services\ActivityProgramService;
use App\Modules\Activity\Application\Services\ActivityRequirementService;
use App\Modules\Activity\Application\Services\ActivityAccessService;
use App\Modules\Activity\Models\ActivityCategory;
use App\Modules\Activity\Models\ActivityPeriod;
use App\Modules\Activity\Models\ActivityProgram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Katalog: kategori, periode, dan program aktivitas (\§15\–19, \§91).
 */
class ActivityCatalogController extends BaseApiController
{
    public function __construct(
        private readonly ActivityCategoryService $categories,
        private readonly ActivityPeriodService $periods,
        private readonly ActivityProgramService $programs,
        private readonly ActivityRequirementService $requirements,
        private readonly ActivityAccessService $access,
    ) {}

    // ------------------------------------------------------------ kategori

    public function categories(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:64', 'unique:activity_categories,code'],
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'integer', 'exists:activity_categories,id'],
            'icon' => ['nullable', 'string', 'max:64'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $category = $this->categories->createCategory($request->all(), $request->user());

        return $this->createdResponse($category, 'Kategori aktivitas dibuat.');
    }

    public function categoryTree(): JsonResponse
    {
        return $this->successResponse($this->categories->tree());
    }

    public function updateCategory(Request $request, ActivityCategory $category): JsonResponse
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:64'],
            'sort_order' => ['nullable', 'integer'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        return $this->successResponse(
            $this->categories->updateCategory($category, $request->all(), $request->user()),
            'Kategori diperbarui.',
        );
    }

    public function deleteCategory(ActivityCategory $category): JsonResponse
    {
        $this->categories->deleteCategory($category);

        return $this->successResponse(null, 'Kategori dihapus.');
    }

    // ------------------------------------------------------------- periode

    public function periods(): JsonResponse
    {
        return $this->successResponse(ActivityPeriod::query()->orderByDesc('start_date')->get());
    }

    public function storePeriod(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:48', 'unique:activity_periods,code'],
            'name' => ['required', 'string', 'max:191'],
            'type' => ['nullable', 'string', 'max:24'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'registration_opens' => ['nullable', 'date'],
            'registration_closes' => ['nullable', 'date'],
            'is_current' => ['nullable', 'boolean'],
        ]);

        return $this->createdResponse($this->periods->create($request->all(), $request->user()), 'Periode aktivitas dibuat.');
    }

    public function markCurrentPeriod(ActivityPeriod $period): JsonResponse
    {
        return $this->successResponse($this->periods->markCurrent($period), 'Periode berjalan diperbarui.');
    }

    // ------------------------------------------------------------- program

    public function programs(Request $request): JsonResponse
    {
        $onlyPublic = ! ($request->user()?->can('activity.program.view') ?? false);

        return $this->successResponse($this->programs->paginate($request->all(), $onlyPublic));
    }

    /** Katalog untuk siswa/orang tua: hanya program yang benar-benar dibuka. */
    public function discover(Request $request): JsonResponse
    {
        $filters = array_merge($request->all(), ['open_registration' => true]);

        return $this->successResponse($this->programs->paginate($filters, true));
    }

    public function storeProgram(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:64', 'unique:activity_programs,code'],
            'name' => ['required', 'string', 'max:191'],
            'category_id' => ['required', 'integer', 'exists:activity_categories,id'],
            'type' => ['nullable', 'string', 'max:32'],
            'delivery_type' => ['nullable', 'string', 'max:24'],
            'minimum_capacity' => ['nullable', 'integer', 'min:0'],
            'maximum_capacity' => ['nullable', 'integer', 'min:0'],
            'selection_method' => ['nullable', 'string', 'max:24'],
            'requirements' => ['nullable', 'array'],
            'skills' => ['nullable', 'array'],
            'objectives' => ['nullable', 'array'],
        ]);

        $program = $this->programs->create($request->all(), $request->user());

        return $this->createdResponse($this->programs->detail($program), 'Program aktivitas dibuat (draf).');
    }

    public function showProgram(ActivityProgram $program): JsonResponse
    {
        return $this->successResponse($this->programs->detail($program));
    }

    public function updateProgram(Request $request, ActivityProgram $program): JsonResponse
    {
        return $this->successResponse(
            $this->programs->update($program, $request->all(), $request->user()),
            'Program aktivitas diperbarui.',
        );
    }

    public function publishProgram(ActivityProgram $program, Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->programs->publish($program, $request->user()),
            'Program aktivitas diterbitkan.',
        );
    }

    public function archiveProgram(ActivityProgram $program, Request $request): JsonResponse
    {
        return $this->successResponse($this->programs->archive($program, $request->user()), 'Program aktivitas diarsipkan.');
    }

    /** Ringkasan kursi: dipakai layar program dan daftar tunggu (\§23). */
    public function capacity(ActivityProgram $program): JsonResponse
    {
        return $this->successResponse($this->programs->capacity($program));
    }

    /** Uji kelayakan tanpa mendaftar \— siswa melihat syarat yang belum dipenuhi. */
    public function checkEligibility(ActivityProgram $program, Request $request): JsonResponse
    {
        $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
        ]);

        $studentId = (int) $request->input('student_id');

        if (! $this->access->canViewStudent($request->user(), $studentId)) {
            return $this->errorResponse('Anda tidak berwenang memeriksa siswa ini.', 403);
        }

        return $this->successResponse($this->requirements->evaluate($program, $studentId));
    }

    /** Program yang saya ikuti/dampingi (pintasan untuk ruang kerja & portal). */
    public function myPrograms(Request $request): JsonResponse
    {
        $user = $request->user();
        $programIds = $this->access->coachedProgramIds($user);

        $query = ActivityProgram::query()
            ->with(['category:id,name', 'period:id,name'])

            ->when($programIds !== [], fn ($q) => $q->whereIn('id', $programIds));

        return $this->successResponse($query->orderBy('name')->limit(50)->get());
    }
}
