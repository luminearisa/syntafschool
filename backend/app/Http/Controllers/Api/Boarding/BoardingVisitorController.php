<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Boarding\VisitorCheckOutRequest;
use App\Http\Requests\Boarding\VisitorRequest;
use App\Http\Resources\BoardingVisitorResource;
use App\Models\BoardingVisitor;
use App\Services\Boarding\BoardingVisitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kunjungan tamu ke asrama (§40) — buku tamu digital: catat kunjungan,
 * sambut tamu (check in), pulangkan (check out), atau batalkan.
 */
class BoardingVisitorController extends BaseApiController
{
    public function __construct(private readonly BoardingVisitorService $visitors)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->visitors->query($request, $request->user());

        if ($request->boolean('all')) {
            return $this->successResponse(
                BoardingVisitorResource::collection($query->get()),
                'Daftar kunjungan berhasil diambil',
            );
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            BoardingVisitorResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar kunjungan berhasil diambil',
        );
    }

    /** Tamu yang masih berada di area asrama — layar "siapa di dalam". */
    public function present(Request $request): JsonResponse
    {
        $request->merge(['present' => 1]);

        return $this->successResponse(
            BoardingVisitorResource::collection($this->visitors->query($request, $request->user())->get()),
            'Daftar tamu yang sedang berkunjung berhasil diambil',
        );
    }

    public function show(Request $request, BoardingVisitor $visitor): JsonResponse
    {
        $this->visitors->assertAccessible($visitor, $request->user());

        return $this->successResponse(
            new BoardingVisitorResource($this->visitors->withDetails($visitor)),
            'Detail kunjungan berhasil diambil',
        );
    }

    public function store(VisitorRequest $request): JsonResponse
    {
        $visitor = $this->visitors->create($request->validated(), $request->user());

        return $this->createdResponse(new BoardingVisitorResource($visitor), 'Kunjungan berhasil dicatat');
    }

    public function checkIn(Request $request, BoardingVisitor $visitor): JsonResponse
    {
        $params = $request->validate(['at' => ['nullable', 'date']]);

        $fresh = $this->visitors->checkIn($visitor, $request->user(), $params['at'] ?? null);

        return $this->successResponse(new BoardingVisitorResource($fresh), 'Tamu tercatat masuk area kunjungan');
    }

    public function checkOut(VisitorCheckOutRequest $request, BoardingVisitor $visitor): JsonResponse
    {
        $data = $request->validated();

        $fresh = $this->visitors->checkOut($visitor, $request->user(), $data['check_out_at'] ?? null, $data['notes'] ?? null);

        return $this->successResponse(new BoardingVisitorResource($fresh), 'Tamu tercatat telah pulang');
    }

    public function cancel(Request $request, BoardingVisitor $visitor): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        $fresh = $this->visitors->cancel($visitor, $request->user(), $note);

        return $this->successResponse(new BoardingVisitorResource($fresh), 'Kunjungan dibatalkan');
    }
}
