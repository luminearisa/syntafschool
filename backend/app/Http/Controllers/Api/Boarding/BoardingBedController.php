<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Boarding\BoardingBedBulkRequest;
use App\Http\Requests\Boarding\BoardingBedRequest;
use App\Http\Resources\BoardingBedResource;
use App\Models\BoardingBed;
use App\Models\BoardingPlacement;
use App\Models\BoardingRoom;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tempat tidur (§8) — unit terkecil penempatan.
 */
class BoardingBedController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = BoardingBed::query()
            ->with(['room:id,code,name', 'activePlacement.student:id,full_name,nis'])
            ->when($request->filled('room_id'), fn (Builder $q) => $q->where('room_id', (int) $request->input('room_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $w) => $w
                    ->where('bed_number', 'like', "%$term%")
                    ->orWhere('label', 'like', "%$term%"));
            })
            ->orderBy('room_id')
            ->orderBy('bed_number');

        if ($request->input('all')) {
            return $this->successResponse(BoardingBedResource::collection($query->get()), 'Daftar bed berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            BoardingBedResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar bed berhasil diambil',
        );
    }

    public function show(BoardingBed $bed): JsonResponse
    {
        $bed->load(['room.floor:id,building_id,floor_number,name', 'activePlacement.student:id,full_name,nis']);

        return $this->successResponse(new BoardingBedResource($bed), 'Detail bed berhasil diambil');
    }

    public function store(BoardingBedRequest $request): JsonResponse
    {
        $bed = BoardingBed::create($request->validated());

        return $this->createdResponse(
            new BoardingBedResource($bed->load('room:id,code,name')),
            'Bed berhasil dibuat',
        );
    }

    /** Buat banyak bed sekaligus untuk satu kamar (§8). */
    public function bulk(BoardingBedBulkRequest $request): JsonResponse
    {
        $roomId = (int) $request->input('room_id');
        $count = (int) $request->input('count');
        $prefix = (string) ($request->input('prefix') ?? '');

        $room = BoardingRoom::findOrFail($roomId);
        $existing = BoardingBed::query()->where('room_id', $roomId)->pluck('bed_number')->all();

        $created = DB::transaction(function () use ($room, $count, $prefix, $existing): array {
            $rows = [];
            $n = 0;

            while (count($rows) < $count) {
                $n++;
                $number = $prefix.str_pad((string) $n, 2, '0', STR_PAD_LEFT);

                if (in_array($number, $existing, true) || isset($rows[$number])) {
                    continue;
                }

                $rows[$number] = [
                    'room_id' => $room->id,
                    'bed_number' => $number,
                    'label' => $prefix === '' ? null : $number,
                    'status' => BoardingBed::STATUS_AVAILABLE,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if ($n >= 100 && count($rows) < $count) {
                    throw ValidationException::withMessages([
                        'count' => 'Nomor bed habis — gunakan prefiks lain.',
                    ]);
                }
            }

            BoardingBed::insert(array_values($rows));

            return array_values($rows);
        });

        $beds = BoardingBed::query()->where('room_id', $room->id)->orderBy('bed_number')->get();

        return $this->createdResponse(
            [
                'created' => count($created),
                'beds' => BoardingBedResource::collection($beds)->resolve(request()),
            ],
            count($created).' bed berhasil dibuat',
        );
    }

    public function update(BoardingBedRequest $request, BoardingBed $bed): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('status', $data) && $data['status'] !== $bed->status) {
            $this->assertStatusChangeAllowed($bed, (string) $data['status']);
        }

        $bed->update($data);

        return $this->successResponse(
            new BoardingBedResource($bed->refresh()->load('room:id,code,name')),
            'Bed berhasil diperbarui',
        );
    }

    public function destroy(BoardingBed $bed): JsonResponse
    {
        if ($bed->isOccupied()) {
            throw ValidationException::withMessages([
                'bed' => 'Bed masih ditempati santri aktif. Pindahkan atau akhiri penempatannya terlebih dahulu.',
            ]);
        }

        $bed->delete();

        return $this->successResponse(null, 'Bed berhasil dihapus');
    }

    /** Bed kosong di satu kamar — untuk dropdown form penempatan. */
    public function available(Request $request): JsonResponse
    {
        $query = BoardingBed::query()
            ->where('status', BoardingBed::STATUS_AVAILABLE)
            ->whereDoesntHave('placements', fn (Builder $p) => $p->where('status', BoardingPlacement::STATUS_ACTIVE))
            ->when($request->filled('room_id'), fn (Builder $q) => $q->where('room_id', (int) $request->input('room_id')))
            ->when($request->filled('dormitory_id'), fn (Builder $q) => $q->whereHas('room', fn (Builder $r) => $r->where('dormitory_id', (int) $request->input('dormitory_id'))))
            ->orderBy('room_id')
            ->orderBy('bed_number');

        return $this->successResponse(
            BoardingBedResource::collection($query->with('room:id,code,name')->get()),
            'Daftar bed tersedia berhasil diambil',
        );
    }

    /**
     * Bed yang sedang ditempati tidak boleh diubah statusnya atau dipindah
     * kamar — historinya terikat placement dan frontend menampilkan bed
     * berdasarkan statusnya.
     */
    private function assertStatusChangeAllowed(BoardingBed $bed, string $status): void
    {
        if ($bed->isOccupied() && $status !== BoardingBed::STATUS_OCCUPIED) {
            throw ValidationException::withMessages([
                'status' => 'Bed masih ditempati — status hanya bisa diubah setelah penempatan diakhiri.',
            ]);
        }
    }
}
