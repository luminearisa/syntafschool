<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Boarding\BoardingRoomRequest;
use App\Http\Resources\BoardingBedResource;
use App\Http\Resources\BoardingPlacementResource;
use App\Http\Resources\BoardingRoomResource;
use App\Models\BoardingBed;
use App\Models\BoardingPlacement;
use App\Models\BoardingRoom;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Kamar asrama (§7) — tempat penempatan santri dan daftar tempat tidur.
 *
 * `current_occupancy`, `beds_count` dan `available_beds` dihitung lewat
 * `withCount` di sini supaya `BoardingRoomResource` tidak memicu query
 * per baris; kamar tanpa kolom-kolom itu tetap tampil normal.
 */
class BoardingRoomController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->baseQuery()
            ->with(['floor:id,building_id,floor_number,name', 'dormitory:id,code,name,gender'])
            ->when($request->filled('dormitory_id'), fn (Builder $q) => $q->where('dormitory_id', (int) $request->input('dormitory_id')))
            ->when($request->filled('building_id'), fn (Builder $q) => $q->whereHas('floor', fn (Builder $f) => $f->where('building_id', (int) $request->input('building_id'))))
            ->when($request->filled('floor_id'), fn (Builder $q) => $q->where('floor_id', (int) $request->input('floor_id')))
            ->when($request->filled('gender'), fn (Builder $q) => $q->where('gender', $request->string('gender')->toString()))
            ->when($request->filled('room_type'), fn (Builder $q) => $q->where('room_type', $request->string('room_type')->toString()))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $w) => $w
                    ->where('code', 'like', "%$term%")
                    ->orWhere('name', 'like', "%$term%"));
            })
            ->orderBy('code');

        if ($request->input('all')) {
            return $this->successResponse(BoardingRoomResource::collection($query->get()), 'Daftar kamar berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            BoardingRoomResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar kamar berhasil diambil',
        );
    }

    public function show(BoardingRoom $room): JsonResponse
    {
        $room->load(['floor.building:id,dormitory_id,code,name', 'dormitory']);

        $beds = $room->beds()
            ->with('activePlacement.student:id,full_name,nis')
            ->orderBy('bed_number')
            ->get();

        $payload = (new BoardingRoomResource($this->withCounts($room)))->resolve(request());

        return $this->successResponse(
            ['room' => $payload, 'beds' => BoardingBedResource::collection($beds)->resolve(request())],
            'Detail kamar berhasil diambil',
        );
    }

    public function store(BoardingRoomRequest $request): JsonResponse
    {
        $room = BoardingRoom::create($request->validated());

        return $this->createdResponse(
            new BoardingRoomResource($this->withCounts($room->load(['floor:id,building_id,floor_number,name', 'dormitory:id,code,name,gender']))),
            'Kamar berhasil dibuat',
        );
    }

    public function update(BoardingRoomRequest $request, BoardingRoom $room): JsonResponse
    {
        $room->update($request->validated());

        return $this->successResponse(
            new BoardingRoomResource($this->withCounts($room->refresh()->load(['floor:id,building_id,floor_number,name', 'dormitory:id,code,name,gender']))),
            'Kamar berhasil diperbarui',
        );
    }

    public function destroy(BoardingRoom $room): JsonResponse
    {
        $active = $room->placements()->where('status', BoardingPlacement::STATUS_ACTIVE)->count();

        if ($active > 0) {
            throw ValidationException::withMessages([
                'room' => "Kamar masih ditempati {$active} santri aktif. Pindahkan atau akhiri penempatannya terlebih dahulu.",
            ]);
        }

        $room->delete();

        return $this->successResponse(null, 'Kamar berhasil dihapus');
    }

    /** Kamar kosong tersedia untuk form penempatan. */
    public function available(Request $request): JsonResponse
    {
        $query = BoardingRoom::query()
            ->where('status', BoardingRoom::STATUS_ACTIVE)
            ->withAvailability()
            ->with(['floor:id,building_id,floor_number,name', 'dormitory:id,code,name,gender'])
            ->when($request->filled('dormitory_id'), fn (Builder $q) => $q->where('dormitory_id', (int) $request->input('dormitory_id')))
            ->when($request->filled('building_id'), fn (Builder $q) => $q->whereHas('floor', fn (Builder $f) => $f->where('building_id', (int) $request->input('building_id'))))
            ->when($request->filled('floor_id'), fn (Builder $q) => $q->where('floor_id', (int) $request->input('floor_id')))
            ->when($request->filled('gender'), fn (Builder $q) => $q->forGender($request->string('gender')->toString()))
            ->orderBy('code');

        return $this->successResponse(
            BoardingRoomResource::collection($this->applyCounts($query)->get()),
            'Daftar kamar tersedia berhasil diambil',
        );
    }

    /** Penghuni aktif satu kamar. */
    public function occupants(BoardingRoom $room): JsonResponse
    {
        $placements = BoardingPlacement::query()
            ->where('room_id', $room->id)
            ->where('status', BoardingPlacement::STATUS_ACTIVE)
            ->with(['student:id,full_name,nis,gender_id', 'bed:id,room_id,bed_number,label'])
            ->orderBy('id')
            ->get();

        return $this->successResponse(
            BoardingPlacementResource::collection($placements),
            'Daftar penghuni kamar berhasil diambil',
        );
    }

    private function baseQuery(): Builder
    {
        return $this->applyCounts(BoardingRoom::query());
    }

    /** Kolom hitungan yang dibaca BoardingRoomResource. */
    private function applyCounts(Builder $query): Builder
    {
        return $query
            ->withCount(['beds'])
            ->withCount(['beds as available_beds' => fn (Builder $b) => $b->where('status', BoardingBed::STATUS_AVAILABLE)])
            ->withCount(['placements as current_occupancy' => fn (Builder $p) => $p->where('status', BoardingPlacement::STATUS_ACTIVE)]);
    }

    private function withCounts(BoardingRoom $room): BoardingRoom
    {
        $room->setAttribute('beds_count', $room->beds()->count());
        $room->setAttribute('available_beds', $room->beds()->where('status', BoardingBed::STATUS_AVAILABLE)->count());
        $room->setAttribute('current_occupancy', $room->placements()->where('status', BoardingPlacement::STATUS_ACTIVE)->count());

        return $room;
    }
}
