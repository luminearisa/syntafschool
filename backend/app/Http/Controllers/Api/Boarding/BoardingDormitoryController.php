<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Boarding\BoardingDormitoryRequest;
use App\Http\Resources\BoardingDormitoryResource;
use App\Models\BoardingBuilding;
use App\Models\BoardingDormitory;
use App\Models\BoardingPlacement;
use App\Models\BoardingRoom;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Master asrama (§6). Kelas tipis: aturan memakai ulang model + guard di rute.
 */
class BoardingDormitoryController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = BoardingDormitory::query()
            ->withCount([
                'buildings',
                'rooms',
                'placements as occupied_count' => fn (Builder $q) => $q->where('status', BoardingPlacement::STATUS_ACTIVE),
            ])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('gender'), fn (Builder $q) => $q->where('gender', $request->string('gender')))
            ->when($request->filled('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $inner) => $inner
                    ->where('code', 'like', "%$term%")
                    ->orWhere('name', 'like', "%$term%")
                    ->orWhere('location', 'like', "%$term%"));
            })
            ->orderBy('code');

        if ($request->input('all')) {
            return $this->successResponse(
                BoardingDormitoryResource::collection($query->get()),
                'Daftar asrama berhasil diambil',
            );
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));
        $dormitories = $query->paginate($perPage)->appends($request->query());

        return $this->successResponse(
            BoardingDormitoryResource::collection($dormitories)->additional([
                'meta' => ['statuses' => BoardingDormitory::STATUSES, 'genders' => BoardingDormitory::GENDERS],
            ]),
            'Daftar asrama berhasil diambil',
        );
    }

    public function show(BoardingDormitory $dormitory): JsonResponse
    {
        $dormitory->load([
            'buildings.floors',
            'rooms',
            'groups',
        ])->loadCount([
            'placements' => fn (Builder $q) => $q->where('status', BoardingPlacement::STATUS_ACTIVE),
        ]);

        return $this->successResponse(
            new BoardingDormitoryResource($dormitory),
            'Detail asrama berhasil diambil',
        );
    }

    public function store(BoardingDormitoryRequest $request): JsonResponse
    {
        $dormitory = BoardingDormitory::create($request->validated());

        return $this->createdResponse(
            new BoardingDormitoryResource($dormitory),
            'Asrama berhasil dibuat',
        );
    }

    public function update(BoardingDormitoryRequest $request, BoardingDormitory $dormitory): JsonResponse
    {
        $dormitory->update($request->validated());

        return $this->successResponse(
            new BoardingDormitoryResource($dormitory->fresh()),
            'Asrama berhasil diperbarui',
        );
    }

    /**
     * Asrama tidak boleh dihapus selama masih ada penghuni aktif — kalau tidak,
     * riwayat penempatan menggantung dan capacity report jadi dusta.
     */
    public function destroy(BoardingDormitory $dormitory): JsonResponse
    {
        $activePlacements = BoardingPlacement::query()
            ->where('dormitory_id', $dormitory->id)
            ->where('status', BoardingPlacement::STATUS_ACTIVE)
            ->count();

        if ($activePlacements > 0) {
            throw ValidationException::withMessages([
                'dormitory' => "Asrama masih menampung {$activePlacements} santri aktif. "
                    .'Akhiri atau mutasi penempatan mereka terlebih dahulu.',
            ]);
        }

        if (BoardingBuilding::query()->where('dormitory_id', $dormitory->id)->exists()
            || BoardingRoom::query()->where('dormitory_id', $dormitory->id)->exists()) {
            throw ValidationException::withMessages([
                'dormitory' => 'Hapus seluruh gedung dan kamar di bawah asrama ini sebelum menghapus asramanya.',
            ]);
        }

        $dormitory->delete();

        return $this->successResponse(null, 'Asrama berhasil dihapus');
    }
}
