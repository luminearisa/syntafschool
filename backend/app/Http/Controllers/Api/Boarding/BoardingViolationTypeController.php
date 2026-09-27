<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Boarding\BoardingViolationTypeRequest;
use App\Http\Resources\BoardingViolationTypeResource;
use App\Models\BoardingViolationType;
use App\Services\Boarding\BoardingViolationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Master jenis pelanggaran asrama (§35). Penghapusan diblokir service selama
 * jenisnya masih dipakai catatan — nonaktifkan, jangan hapus.
 */
class BoardingViolationTypeController extends BaseApiController
{
    public function __construct(private readonly BoardingViolationService $violations)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->violations->typeQuery($request->query());

        if ($request->boolean('all')) {
            return $this->successResponse(
                BoardingViolationTypeResource::collection($query->get()),
                'Master jenis pelanggaran berhasil diambil',
            );
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 25)));

        return $this->successResponse(
            BoardingViolationTypeResource::collection($query->paginate($perPage)->appends($request->query())),
            'Master jenis pelanggaran berhasil diambil',
        );
    }

    public function show(BoardingViolationType $violationType): JsonResponse
    {
        $violationType->loadCount('violations');

        return $this->successResponse(new BoardingViolationTypeResource($violationType), 'Detail jenis pelanggaran berhasil diambil');
    }

    public function store(BoardingViolationTypeRequest $request): JsonResponse
    {
        $type = $this->violations->createType($request->validated(), $request->user());

        return $this->createdResponse(new BoardingViolationTypeResource($type), 'Jenis pelanggaran berhasil dibuat');
    }

    public function update(BoardingViolationTypeRequest $request, BoardingViolationType $violationType): JsonResponse
    {
        $type = $this->violations->updateType($violationType, $request->validated(), $request->user());

        return $this->successResponse(new BoardingViolationTypeResource($type), 'Jenis pelanggaran berhasil diperbarui');
    }

    public function destroy(BoardingViolationType $violationType): JsonResponse
    {
        $this->violations->deleteType($violationType);

        return $this->successResponse(null, 'Jenis pelanggaran berhasil dihapus');
    }
}
