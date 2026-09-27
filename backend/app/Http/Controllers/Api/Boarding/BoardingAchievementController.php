<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Boarding\BoardingAchievementRequest;
use App\Http\Resources\BoardingAchievementResource;
use App\Models\BoardingAchievement;
use App\Services\Boarding\BoardingAchievementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Prestasi santri asrama (§41). Kabar baiknya juga dikirim ke portal orang
 * tua — sisi layanan yang mengirim ada di `BoardingAchievementService`.
 */
class BoardingAchievementController extends BaseApiController
{
    public function __construct(private readonly BoardingAchievementService $achievements)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->achievements->query($request->query(), $request->user());

        if ($request->boolean('all')) {
            return $this->successResponse(
                BoardingAchievementResource::collection($query->get()),
                'Daftar prestasi berhasil diambil',
            );
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            BoardingAchievementResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar prestasi berhasil diambil',
        );
    }

    public function show(Request $request, BoardingAchievement $achievement): JsonResponse
    {
        $this->achievements->assertStudent($achievement->student_id, $request->user());
        $achievement->load(['student:id,full_name,nis', 'recordedBy:id,name']);

        return $this->successResponse(new BoardingAchievementResource($achievement), 'Detail prestasi berhasil diambil');
    }

    /** Dropdown kategori/jenjang — satu sumber dengan aturan validasinya. */
    public function options(): JsonResponse
    {
        return $this->successResponse(BoardingAchievementService::options(), 'Opsi prestasi berhasil diambil');
    }

    public function summary(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->achievements->summary($request->query(), $request->user()),
            'Ringkasan prestasi berhasil diambil',
        );
    }

    public function store(BoardingAchievementRequest $request): JsonResponse
    {
        $achievement = $this->achievements->create($request->validated(), $request->user());
        $achievement->load(['student:id,full_name,nis']);

        return $this->createdResponse(new BoardingAchievementResource($achievement), 'Prestasi berhasil dicatat');
    }

    public function update(BoardingAchievementRequest $request, BoardingAchievement $achievement): JsonResponse
    {
        $fresh = $this->achievements->update($achievement, $request->validated(), $request->user());
        $fresh->load(['student:id,full_name,nis', 'recordedBy:id,name']);

        return $this->successResponse(new BoardingAchievementResource($fresh), 'Prestasi berhasil diperbarui');
    }

    public function destroy(Request $request, BoardingAchievement $achievement): JsonResponse
    {
        $this->achievements->delete($achievement, $request->user());

        return $this->successResponse(null, 'Prestasi berhasil dihapus');
    }
}
