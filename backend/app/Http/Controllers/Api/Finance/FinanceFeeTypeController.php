<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceFeeTypeRequest;
use App\Http\Resources\FinanceFeeTypeResource;
use App\Models\FinanceFeeItem;
use App\Models\FinanceFeeType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Jenis tagihan (§10). Tidak boleh dihapus selama masih dipakai baris paket;
 * nonaktifkan saja agar riwayat tagihan tetap punya rujukan.
 */
class FinanceFeeTypeController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = FinanceFeeType::query()
            ->with('account')
            ->when($request->filled('category'), fn (Builder $q) => $q->where('category', $request->string('category')))
            ->when($request->filled('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $inner) => $inner
                    ->where('code', 'like', "%$term%")
                    ->orWhere('name', 'like', "%$term%"));
            })
            ->orderBy('code');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceFeeTypeResource::collection($query->get()), 'Daftar jenis tagihan berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceFeeTypeResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => ['frequencies' => FinanceFeeType::FREQUENCIES, 'frequency_labels' => FinanceFeeType::FREQUENCY_LABELS]]),
            'Daftar jenis tagihan berhasil diambil',
        );
    }

    public function show(FinanceFeeType $feeType): JsonResponse
    {
        $feeType->load('account');

        return $this->successResponse(new FinanceFeeTypeResource($feeType), 'Detail jenis tagihan berhasil diambil');
    }

    public function store(FinanceFeeTypeRequest $request): JsonResponse
    {
        $feeType = FinanceFeeType::create($request->validated());

        return $this->createdResponse(new FinanceFeeTypeResource($feeType->load('account')), 'Jenis tagihan berhasil dibuat');
    }

    public function update(FinanceFeeTypeRequest $request, FinanceFeeType $feeType): JsonResponse
    {
        $feeType->update($request->validated());

        return $this->successResponse(new FinanceFeeTypeResource($feeType->fresh('account')), 'Jenis tagihan berhasil diperbarui');
    }

    public function destroy(FinanceFeeType $feeType): JsonResponse
    {
        if (FinanceFeeItem::query()->where('fee_type_id', $feeType->id)->exists()) {
            throw ValidationException::withMessages([
                'fee_type' => 'Jenis tagihan masih dipakai paket dan tidak boleh dihapus. Nonaktifkan saja.',
            ]);
        }

        $feeType->delete();

        return $this->successResponse(null, 'Jenis tagihan berhasil dihapus');
    }
}
