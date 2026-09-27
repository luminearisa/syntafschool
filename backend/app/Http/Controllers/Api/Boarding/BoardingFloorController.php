<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Boarding\BoardingFloorRequest;
use App\Http\Resources\BoardingFloorResource;
use App\Models\BoardingFloor;
use App\Models\BoardingPlacement;
use App\Models\BoardingRoom;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Lantai di bawah satu gedung (§6).
 */
class BoardingFloorController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = BoardingFloor::query()
            ->with('building:id,dormitory_id,code,name')
            ->withCount('rooms')
            ->when($request->filled('building_id'), fn (Builder $q) => $q->where('building_id', (int) $request->input('building_id')))
            ->orderBy('building_id')
            ->orderBy('floor_number');

        if ($request->input('all')) {
            return $this->successResponse(BoardingFloorResource::collection($query->get()), 'Daftar lantai berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            BoardingFloorResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar lantai berhasil diambil',
        );
    }

    public function show(BoardingFloor $floor): JsonResponse
    {
        $floor->load(['building.dormitory:id,code,name', 'rooms']);

        return $this->successResponse(new BoardingFloorResource($floor), 'Detail lantai berhasil diambil');
    }

    public function store(BoardingFloorRequest $request): JsonResponse
    {
        $floor = BoardingFloor::create($request->validated());

        return $this->createdResponse(
            new BoardingFloorResource($floor->load('building:id,dormitory_id,code,name')),
            'Lantai berhasil dibuat',
        );
    }

    public function update(BoardingFloorRequest $request, BoardingFloor $floor): JsonResponse
    {
        $floor->update($request->validated());

        return $this->successResponse(
            new BoardingFloorResource($floor->fresh()->load('building:id,dormitory_id,code,name')),
            'Lantai berhasil diperbarui',
        );
    }

    public function destroy(BoardingFloor $floor): JsonResponse
    {
        if (BoardingPlacement::query()->where('floor_id', $floor->id)
            ->where('status', BoardingPlacement::STATUS_ACTIVE)->exists()) {
            throw ValidationException::withMessages([
                'floor' => 'Lantai masih dihuni santri aktif. Pindahkan mereka terlebih dahulu.',
            ]);
        }

        if (BoardingRoom::query()->where('floor_id', $floor->id)->exists()) {
            throw ValidationException::withMessages([
                'floor' => 'Hapus seluruh kamar di lantai ini terlebih dahulu.',
            ]);
        }

        $floor->delete();

        return $this->successResponse(null, 'Lantai berhasil dihapus');
    }
}
