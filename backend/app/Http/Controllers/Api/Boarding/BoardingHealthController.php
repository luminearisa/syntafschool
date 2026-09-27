<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Boarding\BoardingHealthRecordRequest;
use App\Http\Requests\Boarding\BoardingIsolateRequest;
use App\Http\Requests\Boarding\BoardingIsolationReleaseRequest;
use App\Http\Resources\BoardingHealthRecordResource;
use App\Http\Resources\BoardingPlacementResource;
use App\Models\BoardingHealthRecord;
use App\Models\BoardingPlacement;
use App\Services\Boarding\BoardingHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kesehatan asrama (§39): catatan gejala + alur status, dan isolasi sebagai
 * MUTASI PENEMPATAN (bukan flag) — karena itu aksi isolasi mengembalikan
 * placement, bukan catatan kesehatan.
 */
class BoardingHealthController extends BaseApiController
{
    public function __construct(private readonly BoardingHealthService $health)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->health->query($request->query(), $request->user());

        if ($request->boolean('all')) {
            return $this->successResponse(
                BoardingHealthRecordResource::collection($query->get()),
                'Daftar catatan kesehatan berhasil diambil',
            );
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            BoardingHealthRecordResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar catatan kesehatan berhasil diambil',
        );
    }

    public function show(Request $request, BoardingHealthRecord $healthRecord): JsonResponse
    {
        $this->health->assertStudent($healthRecord->student_id, $request->user());
        $healthRecord->load(['student:id,full_name,nis', 'recordedBy:id,name']);

        return $this->successResponse(new BoardingHealthRecordResource($healthRecord), 'Detail catatan kesehatan berhasil diambil');
    }

    public function store(BoardingHealthRecordRequest $request): JsonResponse
    {
        $record = $this->health->create($request->validated(), $request->user());
        $record->load(['student:id,full_name,nis']);

        return $this->createdResponse(new BoardingHealthRecordResource($record), 'Catatan kesehatan berhasil dibuat');
    }

    public function update(BoardingHealthRecordRequest $request, BoardingHealthRecord $healthRecord): JsonResponse
    {
        $fresh = $this->health->update($healthRecord, $request->validated(), $request->user());
        $fresh->load(['student:id,full_name,nis', 'recordedBy:id,name']);

        return $this->successResponse(new BoardingHealthRecordResource($fresh), 'Catatan kesehatan berhasil diperbarui');
    }

    /** Transisi status mengikuti tabel TRANSITIONS (§39); `referred` wajib `action`. */
    public function status(Request $request, BoardingHealthRecord $healthRecord): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', BoardingHealthRecord::STATUSES)],
            'action' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $extra = array_filter([
            'action' => $data['action'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);

        $fresh = $this->health->updateStatus($healthRecord, (string) $data['status'], $request->user(), $extra);
        $fresh->load(['student:id,full_name,nis']);

        return $this->successResponse(
            new BoardingHealthRecordResource($fresh),
            'Status kesehatan berhasil diperbarui ke '.$fresh->statusLabel(),
        );
    }

    public function destroy(Request $request, BoardingHealthRecord $healthRecord): JsonResponse
    {
        $this->health->delete($healthRecord, $request->user());

        return $this->successResponse(null, 'Catatan kesehatan berhasil dihapus');
    }

    /**
     * Pindahkan santri ke kamar isolasi (§39) — menutup placement lama,
     * membuka placement baru di kamar bertipe `isolation`.
     */
    public function isolate(BoardingIsolateRequest $request): JsonResponse
    {
        $data = $request->validated();

        $placement = $this->health->isolate(
            $data['student_id'],
            $data['room_id'],
            $data['bed_id'] ?? null,
            $data['reason'] ?? null,
            $request->user(),
            [
                'health_record_id' => $data['health_record_id'] ?? null,
                'notes' => $data['notes'] ?? null,
            ],
        );

        $placement->load(['student:id,full_name,nis', 'room', 'dormitory', 'bed', 'building', 'floor']);

        return $this->createdResponse(
            new BoardingPlacementResource($placement),
            'Santri berhasil dipindahkan ke ruang isolasi',
        );
    }

    public function releaseIsolation(BoardingIsolationReleaseRequest $request, BoardingPlacement $placement): JsonResponse
    {
        $data = $request->validated();

        $fresh = $this->health->releaseIsolation(
            $placement,
            $data['room_id'] ?? null,
            $data['bed_id'] ?? null,
            $data['reason'] ?? null,
            $request->user(),
        );

        $fresh->load(['student:id,full_name,nis', 'room', 'dormitory', 'bed', 'building', 'floor']);

        return $this->successResponse(
            new BoardingPlacementResource($fresh),
            'Santri berhasil dikeluarkan dari ruang isolasi',
        );
    }

    /** Daftar penghuni ruang isolasi — "siapa di isolasi". */
    public function isolations(Request $request): JsonResponse
    {
        return $this->successResponse(
            BoardingPlacementResource::collection($this->health->activeIsolations($request->user())),
            'Daftar penghuni ruang isolasi berhasil diambil',
        );
    }
}
