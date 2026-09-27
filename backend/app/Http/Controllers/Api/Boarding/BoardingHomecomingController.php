<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Boarding\HomecomingRequest;
use App\Http\Resources\BoardingHomecomingResource;
use App\Models\BoardingHomecoming;
use App\Services\Boarding\BoardingHomecomingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kepulangan santri ke rumah (§30–§31): pengajuan, persetujuan, catatan
 * berangkat (check out) dan kembali (check in + terlambat).
 */
class BoardingHomecomingController extends BaseApiController
{
    public function __construct(private readonly BoardingHomecomingService $homecomings)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->homecomings->query($request, $request->user());

        if ($request->boolean('all')) {
            return $this->successResponse(
                BoardingHomecomingResource::collection($query->get()),
                'Daftar kepulangan berhasil diambil',
            );
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            BoardingHomecomingResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar kepulangan berhasil diambil',
        );
    }

    public function show(Request $request, BoardingHomecoming $homecoming): JsonResponse
    {
        $this->homecomings->assertAccessible($homecoming, $request->user());

        return $this->successResponse(
            new BoardingHomecomingResource($this->homecomings->withDetails($homecoming)),
            'Detail kepulangan berhasil diambil',
        );
    }

    public function store(HomecomingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $submit = (bool) ($data['submit'] ?? false);
        unset($data['submit']);

        $homecoming = $this->homecomings->create($data, $request->user(), $submit);

        return $this->createdResponse(new BoardingHomecomingResource($homecoming), 'Data kepulangan berhasil disimpan');
    }

    public function submit(Request $request, BoardingHomecoming $homecoming): JsonResponse
    {
        $fresh = $this->homecomings->submit($homecoming, $request->user());

        return $this->successResponse(new BoardingHomecomingResource($fresh), 'Kepulangan berhasil diajukan untuk persetujuan');
    }

    public function approve(Request $request, BoardingHomecoming $homecoming): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        $fresh = $this->homecomings->approve($homecoming, $request->user(), $note);

        return $this->successResponse(new BoardingHomecomingResource($fresh), 'Kepulangan disetujui');
    }

    public function reject(Request $request, BoardingHomecoming $homecoming): JsonResponse
    {
        $note = $request->validate([
            'note' => ['required', 'string', 'min:5', 'max:1000'],
        ])['note'];

        $fresh = $this->homecomings->reject($homecoming, $request->user(), $note);

        return $this->successResponse(new BoardingHomecomingResource($fresh), 'Kepulangan ditolak');
    }

    public function checkOut(Request $request, BoardingHomecoming $homecoming): JsonResponse
    {
        $params = $request->validate(['at' => ['nullable', 'date']]);

        $fresh = $this->homecomings->checkOut($homecoming, $request->user(), $params['at'] ?? null);

        return $this->successResponse(new BoardingHomecomingResource($fresh), 'Santri tercatat pulang');
    }

    public function checkIn(Request $request, BoardingHomecoming $homecoming): JsonResponse
    {
        $params = $request->validate(['at' => ['nullable', 'date']]);

        $fresh = $this->homecomings->checkIn($homecoming, $request->user(), $params['at'] ?? null);

        return $this->successResponse(
            new BoardingHomecomingResource($fresh),
            $fresh->late_return ? 'Santri kembali — tercatat TERLAMBAT' : 'Santri tercatat kembali ke asrama',
        );
    }

    public function cancel(Request $request, BoardingHomecoming $homecoming): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        $fresh = $this->homecomings->cancel($homecoming, $request->user(), $note);

        return $this->successResponse(new BoardingHomecomingResource($fresh), 'Data kepulangan dibatalkan');
    }
}
