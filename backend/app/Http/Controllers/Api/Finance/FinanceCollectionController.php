<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceCollectionActionRequest;
use App\Http\Resources\FinanceCollectionActionResource;
use App\Models\FinanceInvoice;
use App\Services\Finance\FinanceCollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Penagihan / collection (§6): dashboard penagihan, antrean AR per stage,
 * sejarah aksi, dan pencatatan aksi.
 *
 * Endpoint di sini tidak punya jalur untuk mengubah nominal. Sisa piutang
 * tetap milik mesin pembayaran (§17), penyesuaian, dan write-off (§32) —
 * halaman collection hanya membaca angka itu dan mencatat tindakannya.
 */
class FinanceCollectionController extends BaseApiController
{
    public function __construct(private readonly FinanceCollectionService $collections) {}

    /** Antrean penagihan: invoice outstanding + stage workflow + aksi terakhir. */
    public function index(Request $request): JsonResponse
    {
        return $this->successResponse($this->collections->queue($request->query()), 'Antrean penagihan berhasil diambil');
    }

    /** Dashboard penagihan §6: AR per ember umur, per kelompok, per stage, top penunggak. */
    public function dashboard(Request $request): JsonResponse
    {
        return $this->successResponse($this->collections->dashboard($request->query()), 'Dashboard penagihan berhasil diambil');
    }

    /** Sejarah aksi penagihan sebuah invoice (§6: "simpan history setiap collection action"). */
    public function history(FinanceInvoice $invoice): JsonResponse
    {
        return $this->successResponse(
            FinanceCollectionActionResource::collection($this->collections->history($invoice)),
            'Sejarah penagihan berhasil diambil',
        );
    }

    /** Catat satu aksi. Aksi tidak menggerakkan satu rupiah pun angka piutang. */
    public function store(FinanceCollectionActionRequest $request, FinanceInvoice $invoice): JsonResponse
    {
        $action = $this->collections->record($invoice, $request->validated(), $request->user());

        return $this->createdResponse(
            new FinanceCollectionActionResource($action),
            'Aksi penagihan berhasil dicatat',
        );
    }
}
