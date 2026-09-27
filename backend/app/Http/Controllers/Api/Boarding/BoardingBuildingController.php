<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Boarding\BoardingBuildingRequest;
use App\Http\Resources\BoardingBuildingResource;
use App\Models\BoardingBuilding;
use App\Models\BoardingDormitory;
use App\Models\BoardingFloor;
use App\Models\BoardingPlacement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Gedung di bawah satu asrama (§5).
 */
class BoardingBuildingController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = BoardingBuilding::query()
            ->with('dormitory:id,code,name')
            ->withCount('floors')
            ->when($request->filled('dormitory_id'), fn (Builder $q) => $q->where('dormitory_id', (int) $request->input('dormitory_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $inner) => $inner
                    ->where('code', 'like', "%$term%")
                    ->orWhere('name', 'like', "%$term%"));
            })
            ->orderBy('dormitory_id')
            ->orderBy('code');

        if ($request->input('all')) {
            return $this->successResponse(BoardingBuildingResource::collection($query->get()), 'Daftar gedung berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            BoardingBuildingResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar gedung berhasil diambil',
        );
    }

    public function show(BoardingBuilding $building): JsonResponse
    {
        $building->load(['dormitory', 'floors' => fn (Builder $q) => $q->orderBy('floor_number')]);

        return $this->successResponse(new BoardingBuildingResource($building), 'Detail gedung berhasil diambil');
    }

    public function store(BoardingBuildingRequest $request): JsonResponse
    {
        $building = BoardingBuilding::create($request->validated());

        return $this->createdResponse(
            new BoardingBuildingResource($building->load('dormitory:id,code,name')),
            'Gedung berhasil dibuat',
        );
    }

    public function update(BoardingBuildingRequest $request, BoardingBuilding $building): JsonResponse
    {
        $building->update($request->validated());

        return $this->successResponse(
            new BoardingBuildingResource($building->fresh()->load('dormitory:id,code,name')),
            'Gedung berhasil diperbarui',
        );
    }

    public function destroy(BoardingBuilding $building): JsonResponse
    {
        if (BoardingPlacement::query()->where('building_id', $building->id)
            ->where('status', BoardingPlacement::STATUS_ACTIVE)->exists()) {
            throw ValidationException::withMessages([
                'building' => 'Gedung masih dihuni santri aktif. Pindahkan mereka sebelum menghapus gedung.',
            ]);
        }

        if (BoardingFloor::query()->where('building_id', $building->id)->exists()) {
            throw ValidationException::withMessages([
                'building' => 'Hapus seluruh lantai di bawah gedung ini terlebih dahulu.',
            ]);
        }

        $building->delete();

        return $this->successResponse(null, 'Gedung berhasil dihapus');
    }
}
