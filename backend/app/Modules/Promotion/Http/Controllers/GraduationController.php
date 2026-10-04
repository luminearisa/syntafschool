<?php

namespace App\Modules\Promotion\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\AcademicYear;
use App\Models\EducationLevel;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Modules\Promotion\Application\Services\GraduationService;
use App\Modules\Promotion\Http\Requests\BulkStoreGraduationRequest;
use App\Modules\Promotion\Http\Requests\EvaluateGraduationRequest;
use App\Modules\Promotion\Http\Requests\UpdateGraduationRequest;
use App\Modules\Promotion\Http\Resources\GraduationResource;
use App\Modules\Promotion\Models\Graduation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GraduationController extends BaseApiController
{
    public function __construct(private readonly GraduationService $service) {}

    public function lookups(): JsonResponse
    {
        return $this->successResponse([
            'academic_years' => AcademicYear::query()->orderByDesc('start_date')->get(['id', 'name', 'is_active']),
            'education_levels' => EducationLevel::query()->orderBy('jenjang')->orderBy('level_number')->get(['id', 'name', 'jenjang', 'level_number']),
            'classes' => SchoolClass::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'academic_year_id', 'education_level_id']),
            'semesters' => Semester::query()->with('academicYear:id,name')->orderByDesc('start_date')->get(['id', 'academic_year_id', 'name', 'is_active']),
            'decisions' => Graduation::DECISION_LABELS,
        ], 'Referensi kelulusan berhasil diambil');
    }

    public function index(Request $request): JsonResponse
    {
        $query = Graduation::query()
            ->with(['student', 'academicYear'])
            ->when($request->filled('academic_year_id'), fn (Builder $q) => $q->where('academic_year_id', (int) $request->input('academic_year_id')))
            ->when($request->filled('decision'), fn (Builder $q) => $q->where('decision', $request->string('decision')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->whereHas('student', fn (Builder $student) => $student
                    ->where('full_name', 'like', "%{$term}%")
                    ->orWhere('nis', 'like', "%{$term}%"));
            })
            ->orderByDesc('id');

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            GraduationResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar kelulusan berhasil diambil',
        );
    }

    public function evaluate(EvaluateGraduationRequest $request): JsonResponse
    {
        $result = $this->service->evaluate(
            (int) $request->validated('academic_year_id'),
            $request->validated('education_level_id') === null ? null : (int) $request->validated('education_level_id'),
            $request->validated('class_id') === null ? null : (int) $request->validated('class_id'),
            $request->validated('semester_id') === null ? null : (int) $request->validated('semester_id'),
        );

        return $this->successResponse($result, 'Pratinjau kelulusan berhasil dihitung');
    }

    public function bulk(BulkStoreGraduationRequest $request): JsonResponse
    {
        $saved = $this->service->bulk(
            (int) $request->validated('academic_year_id'),
            $request->validated('graduations'),
        );

        return $this->createdResponse(
            GraduationResource::collection($saved),
            'Keputusan kelulusan berhasil disimpan',
        );
    }

    public function show(Graduation $graduation): JsonResponse
    {
        $graduation->load(['student', 'academicYear', 'approvedBy']);

        return $this->successResponse(new GraduationResource($graduation), 'Detail kelulusan berhasil diambil');
    }

    public function update(UpdateGraduationRequest $request, Graduation $graduation): JsonResponse
    {
        $updated = $this->service->update($graduation, $request->validated());

        return $this->successResponse(new GraduationResource($updated), 'Kelulusan berhasil diperbarui');
    }

    public function approve(Request $request, Graduation $graduation): JsonResponse
    {
        $updated = $this->service->approve($graduation, $request->user()?->id);

        return $this->successResponse(new GraduationResource($updated), 'Kelulusan berhasil disetujui dan nomor diterbitkan');
    }

    public function destroy(Graduation $graduation): JsonResponse
    {
        if ($graduation->isApproved()) {
            return $this->errorResponse('Kelulusan yang sudah disetujui tidak dapat dihapus.', 422);
        }

        $graduation->delete();

        return $this->successResponse(null, 'Keputusan kelulusan berhasil dihapus');
    }
}
