<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Boarding\BoardingViolationRequest;
use App\Http\Resources\BoardingViolationResource;
use App\Models\BoardingViolation;
use App\Models\Student;
use App\Services\Boarding\BoardingViolationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pencatatan pelanggaran santri (§36). Poin adalah snapshot dari master di
 * dalam service — klien tidak pernah mengirim `points`.
 */
class BoardingViolationController extends BaseApiController
{
    public function __construct(private readonly BoardingViolationService $violations)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->violations->query($request->query(), $request->user());

        if ($request->boolean('all')) {
            return $this->successResponse(
                BoardingViolationResource::collection($query->get()),
                'Daftar pelanggaran berhasil diambil',
            );
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            BoardingViolationResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar pelanggaran berhasil diambil',
        );
    }

    public function show(Request $request, BoardingViolation $violation): JsonResponse
    {
        $this->violations->assertStudent($violation->student_id, $request->user());
        $violation->load(['student:id,full_name,nis', 'type', 'recordedBy:id,name', 'disciplinaryActions']);

        return $this->successResponse(new BoardingViolationResource($violation), 'Detail pelanggaran berhasil diambil');
    }

    /**
     * Catat pelanggaran + ambang poin dievaluasi (§37). Aksi otomatis yang lahir
     * dari ambang ikut dilaporkan agar petugas tahu apa yang baru dibuat.
     */
    public function store(BoardingViolationRequest $request): JsonResponse
    {
        [$violation, $actions] = $this->violations->record($request->validated(), $request->user());

        return $this->createdResponse([
            'violation' => BoardingViolationResource::make($violation),
            'auto_actions' => array_map(fn ($action): array => [
                'id' => (int) $action->id,
                'action' => $action->action,
                'action_label' => $action->actionLabel(),
                'notes' => $action->notes,
            ], $actions),
        ], $actions === []
            ? 'Pelanggaran berhasil dicatat'
            : 'Pelanggaran berhasil dicatat; '.count($actions).' tindakan ambang poin dibuat otomatis');
    }

    public function update(BoardingViolationRequest $request, BoardingViolation $violation): JsonResponse
    {
        $fresh = $this->violations->update($violation, $request->validated(), $request->user());
        $fresh->load(['student:id,full_name,nis', 'type']);

        return $this->successResponse(new BoardingViolationResource($fresh), 'Pelanggaran berhasil diperbarui');
    }

    public function destroy(Request $request, BoardingViolation $violation): JsonResponse
    {
        $this->violations->delete($violation, $request->user());

        return $this->successResponse(null, 'Pelanggaran berhasil dihapus');
    }

    /**
     * Ringkasan poin satu santri (§37): total, rincian kategori, ambang, recent.
     */
    public function points(Request $request, Student $student): JsonResponse
    {
        $limit = max(0, min(50, (int) $request->query('recent', BoardingViolationService::RECENT_LIMIT)));

        return $this->successResponse(
            $this->violations->pointsSummary($student, $request->user(), $limit),
            'Ringkasan poin berhasil diambil',
        );
    }
}
