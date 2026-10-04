<?php

namespace App\Modules\StudentProgress\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\AchievementType;
use App\Models\Student;
use App\Modules\StudentProgress\Application\Services\AchievementService;
use App\Modules\StudentProgress\Http\Requests\StoreAchievementRequest;
use App\Modules\StudentProgress\Http\Requests\UpdateAchievementRequest;
use App\Modules\StudentProgress\Http\Resources\StudentAchievementResource;
use App\Modules\StudentProgress\Models\StudentAchievement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Prestasi siswa (2D — Prestasi & Pelanggaran).
 */
class StudentAchievementController extends BaseApiController
{
    public function __construct(private readonly AchievementService $achievements) {}

    /**
     * Referensi form: siswa, jenis prestasi, dan daftar tingkat.
     */
    public function lookups(): JsonResponse
    {
        return $this->successResponse([
            'students' => Student::query()->orderBy('full_name')->get(['id', 'nis', 'full_name']),
            'achievement_types' => AchievementType::query()->orderBy('name')->get(['id', 'name', 'level']),
            'levels' => StudentAchievement::LEVELS,
        ], 'Referensi prestasi berhasil diambil');
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->achievements->query($request->query());

        if ($request->boolean('all')) {
            return $this->successResponse(
                StudentAchievementResource::collection($query->get()),
                'Daftar prestasi berhasil diambil',
            );
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            StudentAchievementResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar prestasi berhasil diambil',
        );
    }

    public function store(StoreAchievementRequest $request): JsonResponse
    {
        $achievement = $this->achievements->create($request->validated());

        return $this->createdResponse(
            new StudentAchievementResource($achievement),
            'Prestasi berhasil dicatat',
        );
    }

    public function show(StudentAchievement $achievement): JsonResponse
    {
        $achievement->load(['student:id,nis,full_name', 'achievementType:id,name,level']);

        return $this->successResponse(
            new StudentAchievementResource($achievement),
            'Detail prestasi berhasil diambil',
        );
    }

    public function update(UpdateAchievementRequest $request, StudentAchievement $achievement): JsonResponse
    {
        $achievement = $this->achievements->update($achievement, $request->validated());
        $achievement->load(['student:id,nis,full_name', 'achievementType:id,name,level']);

        return $this->successResponse(
            new StudentAchievementResource($achievement),
            'Prestasi berhasil diperbarui',
        );
    }

    public function destroy(StudentAchievement $achievement): JsonResponse
    {
        $this->achievements->delete($achievement);

        return $this->successResponse(null, 'Prestasi berhasil dihapus');
    }
}
