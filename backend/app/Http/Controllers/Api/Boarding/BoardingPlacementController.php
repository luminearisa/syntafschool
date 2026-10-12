<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Boarding\BoardingPlacementAssignBatchRequest;
use App\Http\Requests\Boarding\BoardingPlacementAssignRequest;
use App\Http\Requests\Boarding\BoardingPlacementEndRequest;
use App\Http\Requests\Boarding\BoardingPlacementTransferRequest;
use App\Http\Resources\BoardingPlacementResource;
use App\Models\BoardingPlacement;
use App\Models\Student;
use App\Services\Boarding\BoardingPlacementService;
use App\Services\Boarding\BoardingScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Penempatan santri (§9–§12). Semua aturan bisnis (kapasitas, gender, bed
 * terisi, placement ganda, rantai transfer) hidup di `BoardingPlacementService`;
 * controller hanya memvalidasi bentuk dan membungkus respons.
 */
class BoardingPlacementController extends BaseApiController
{
    public function __construct(
        private readonly BoardingPlacementService $placements,
        private readonly BoardingScopeService $scope,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->placements->query($request, $request->user());

        if ($request->input('all')) {
            return $this->successResponse(
                BoardingPlacementResource::collection($query->get()),
                'Daftar penempatan berhasil diambil',
            );
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            BoardingPlacementResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar penempatan berhasil diambil',
        );
    }

    public function show(BoardingPlacement $placement): JsonResponse
    {
        $placement = $this->placements->withDetails($placement);
        $this->scope->assertCanAccessStudent(request()->user(), $placement->student_id);

        return $this->successResponse(new BoardingPlacementResource($placement), 'Detail penempatan berhasil diambil');
    }

    public function store(BoardingPlacementAssignRequest $request): JsonResponse
    {
        $placement = $this->placements->assign($request->validated(), $request->user());

        return $this->createdResponse(
            new BoardingPlacementResource($this->placements->withDetails($placement)),
            'Penempatan santri berhasil dibuat',
        );
    }

    /**
     * Penempatan massal: banyak santri ke satu kamar sekaligus. Semua
     * penempatan bersifat all-or-nothing (satu transaksi) — lihat
     * `BoardingPlacementService::assignMany()`.
     */
    public function storeBatch(BoardingPlacementAssignBatchRequest $request): JsonResponse
    {
        $data = $request->validated();
        $studentIds = $data['student_ids'];
        unset($data['student_ids']);

        $placements = $this->placements->assignMany($studentIds, $data, $request->user());

        return $this->createdResponse(
            BoardingPlacementResource::collection($placements),
            count($placements).' penempatan santri berhasil dibuat',
        );
    }

    public function transfer(BoardingPlacementTransferRequest $request, BoardingPlacement $placement): JsonResponse
    {
        $this->scope->assertCanAccessStudent($request->user(), $placement->student_id);

        $result = $this->placements->transfer($placement, $request->validated(), $request->user());

        return $this->successResponse(
            new BoardingPlacementResource($this->placements->withDetails($result)),
            'Mutasi santri berhasil dicatat',
        );
    }

    public function end(BoardingPlacementEndRequest $request, BoardingPlacement $placement): JsonResponse
    {
        $this->scope->assertCanAccessStudent($request->user(), $placement->student_id);

        $result = $this->placements->end($placement, $request->validated(), $request->user());

        return $this->successResponse(
            new BoardingPlacementResource($this->placements->withDetails($result)),
            'Penempatan santri berhasil diakhiri',
        );
    }

    /** Riwayat lengkap satu santri — aktif maupun lama (§11). */
    public function history(Request $request, Student $student): JsonResponse
    {
        $this->scope->assertCanAccessStudent($request->user(), $student->id);

        return $this->successResponse(
            BoardingPlacementResource::collection($this->placements->historyQuery($student)->get()),
            'Riwayat penempatan berhasil diambil',
        );
    }

    /** Penempatan aktif satu santri (atau null) — untuk kartu dashboard. */
    public function current(Request $request, Student $student): JsonResponse
    {
        $this->scope->assertCanAccessStudent($request->user(), $student->id);

        $placement = BoardingPlacement::query()
            ->where('student_id', $student->id)
            ->where('status', BoardingPlacement::STATUS_ACTIVE)
            ->latest('id')
            ->first();

        return $this->successResponse(
            $placement === null ? null : new BoardingPlacementResource($this->placements->withDetails($placement)),
            'Penempatan aktif berhasil diambil',
        );
    }

    /** Ringkasan okupansi satu kamar (kebutuhan form transfer). */
    public function occupancy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer', 'exists:boarding_rooms,id'],
        ]);

        return $this->successResponse(
            $this->placements->roomOccupancy(\App\Models\BoardingRoom::findOrFail($data['room_id'])),
            'Okupansi kamar berhasil diambil',
        );
    }
}
