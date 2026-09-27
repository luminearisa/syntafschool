<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Boarding\BoardingDisciplinaryActionRequest;
use App\Http\Requests\Boarding\BoardingDisciplinaryActionStatusRequest;
use App\Http\Resources\BoardingDisciplinaryActionResource;
use App\Models\BoardingDisciplinaryAction;
use App\Services\Boarding\BoardingViolationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tindakan pendisiplinan (§37–§38): antrean kerja petugas. Yang otomatis
 * (lahir dari ambang poin) dan yang manual memakai bentuk yang sama —
 * `violation_id` null menandai yang otomatis.
 */
class BoardingDisciplinaryActionController extends BaseApiController
{
    public function __construct(private readonly BoardingViolationService $violations)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->violations->actionQuery($request->query(), $request->user());

        if ($request->boolean('all')) {
            return $this->successResponse(
                BoardingDisciplinaryActionResource::collection($query->get()),
                'Daftar tindakan pendisiplinan berhasil diambil',
            );
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            BoardingDisciplinaryActionResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar tindakan pendisiplinan berhasil diambil',
        );
    }

    public function show(Request $request, BoardingDisciplinaryAction $action): JsonResponse
    {
        $this->violations->assertStudent($action->student_id, $request->user());
        $action->load(['student:id,full_name,nis', 'violation:id,violation_type_id,date,points', 'recordedBy:id,name']);

        return $this->successResponse(new BoardingDisciplinaryActionResource($action), 'Detail tindakan berhasil diambil');
    }

    public function store(BoardingDisciplinaryActionRequest $request): JsonResponse
    {
        $action = $this->violations->recordAction($request->validated(), $request->user());
        $action->load(['student:id,full_name,nis', 'violation:id,violation_type_id,date,points']);

        return $this->createdResponse(new BoardingDisciplinaryActionResource($action), 'Tindakan pendisiplinan berhasil dicatat');
    }

    public function update(BoardingDisciplinaryActionRequest $request, BoardingDisciplinaryAction $action): JsonResponse
    {
        $fresh = $this->violations->updateAction($action, $request->validated(), $request->user());
        $fresh->load(['student:id,full_name,nis', 'violation:id,violation_type_id,date,points']);

        return $this->successResponse(new BoardingDisciplinaryActionResource($fresh), 'Tindakan pendisiplinan berhasil diperbarui');
    }

    /** Tutup antrean: pending → completed/cancelled (§38). */
    public function status(BoardingDisciplinaryActionStatusRequest $request, BoardingDisciplinaryAction $action): JsonResponse
    {
        $data = $request->validated();

        $fresh = $this->violations->updateActionStatus($action, (string) $data['status'], $request->user(), $data);
        $fresh->load(['student:id,full_name,nis']);

        return $this->successResponse(
            new BoardingDisciplinaryActionResource($fresh),
            'Status tindakan berhasil diperbarui',
        );
    }

    public function destroy(Request $request, BoardingDisciplinaryAction $action): JsonResponse
    {
        $this->violations->deleteAction($action, $request->user());

        return $this->successResponse(null, 'Tindakan pendisiplinan berhasil dihapus');
    }
}
