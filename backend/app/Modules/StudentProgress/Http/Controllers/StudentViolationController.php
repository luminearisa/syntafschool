<?php

namespace App\Modules\StudentProgress\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Student;
use App\Models\ViolationType;
use App\Modules\StudentProgress\Application\Services\ViolationService;
use App\Modules\StudentProgress\Http\Requests\StoreViolationRequest;
use App\Modules\StudentProgress\Http\Requests\UpdateViolationRequest;
use App\Modules\StudentProgress\Http\Resources\StudentViolationResource;
use App\Modules\StudentProgress\Models\StudentViolation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pelanggaran siswa & poin kedisiplinan (2D — Prestasi & Pelanggaran).
 */
class StudentViolationController extends BaseApiController
{
    public function __construct(private readonly ViolationService $violations) {}

    /**
     * Referensi form: siswa dan jenis pelanggaran aktif.
     */
    public function lookups(): JsonResponse
    {
        return $this->successResponse([
            'students' => Student::query()->orderBy('full_name')->get(['id', 'nis', 'full_name']),
            'violation_types' => ViolationType::query()
                ->orderBy('name')
                ->get(['id', 'name', 'category', 'point', 'is_active']),
        ], 'Referensi pelanggaran berhasil diambil');
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->violations->query($request->query());

        if ($request->boolean('all')) {
            return $this->successResponse(
                StudentViolationResource::collection($query->get()),
                'Daftar pelanggaran berhasil diambil',
            );
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            StudentViolationResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar pelanggaran berhasil diambil',
        );
    }

    /**
     * Catat pelanggaran. Bila total poin melewati ambang, alert ikut dilaporkan.
     */
    public function store(StoreViolationRequest $request): JsonResponse
    {
        [$violation, $alert] = $this->violations->record($request->validated(), $request->user());
        $violation->load(['student:id,nis,full_name', 'violationType:id,name,category,point', 'reporter:id,full_name']);

        return $this->createdResponse([
            'violation' => new StudentViolationResource($violation),
            'alert' => $alert === null ? null : [
                'id' => (int) $alert->id,
                'level' => $alert->level,
                'message' => $alert->message,
            ],
        ], $alert === null ? 'Pelanggaran berhasil dicatat' : 'Pelanggaran dicatat; peringatan poin dibuat otomatis');
    }

    public function show(StudentViolation $violation): JsonResponse
    {
        $violation->load(['student:id,nis,full_name', 'violationType:id,name,category,point', 'reporter:id,full_name']);

        return $this->successResponse(
            new StudentViolationResource($violation),
            'Detail pelanggaran berhasil diambil',
        );
    }

    public function update(UpdateViolationRequest $request, StudentViolation $violation): JsonResponse
    {
        $violation = $this->violations->update($violation, $request->validated());
        $violation->load(['student:id,nis,full_name', 'violationType:id,name,category,point', 'reporter:id,full_name']);

        return $this->successResponse(
            new StudentViolationResource($violation),
            'Pelanggaran berhasil diperbarui',
        );
    }

    /**
     * Verifikasi: `recorded` → `verified`.
     */
    public function verify(StudentViolation $violation): JsonResponse
    {
        $violation = $this->violations->verify($violation);
        $violation->load(['student:id,nis,full_name', 'violationType:id,name,category,point']);

        return $this->successResponse(
            new StudentViolationResource($violation),
            'Pelanggaran berhasil diverifikasi',
        );
    }

    /**
     * Penyelesaian: `verified` → `resolved`.
     */
    public function resolve(StudentViolation $violation): JsonResponse
    {
        $violation = $this->violations->resolve($violation);
        $violation->load(['student:id,nis,full_name', 'violationType:id,name,category,point']);

        return $this->successResponse(
            new StudentViolationResource($violation),
            'Pelanggaran berhasil diselesaikan',
        );
    }

    public function destroy(StudentViolation $violation): JsonResponse
    {
        $this->violations->delete($violation);

        return $this->successResponse(null, 'Pelanggaran berhasil dihapus');
    }

    /**
     * Ringkasan poin satu siswa: total, ambang, level, dan rincian per jenis.
     */
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'min:1', 'exists:students,id'],
            'semester_id' => ['nullable', 'integer', 'min:1', 'exists:semesters,id'],
            'threshold' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        return $this->successResponse(
            $this->violations->summary(
                (int) $validated['student_id'],
                isset($validated['semester_id']) ? (int) $validated['semester_id'] : null,
                isset($validated['threshold']) ? (int) $validated['threshold'] : null,
            ),
            'Ringkasan poin berhasil diambil',
        );
    }
}
