<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceDiscountRequest;
use App\Http\Resources\FinanceDiscountResource;
use App\Models\FinanceDiscount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aturan diskon reusable (§17). CRUD sederhana; penerapan ke tagihan terjadi saat
 * billing digenerate (`FinanceBillingService`), bukan di sini.
 */
class FinanceDiscountController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = FinanceDiscount::query()
            ->with(['feeType', 'program', 'student'])
            ->when($request->filled('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->string('type')))
            ->when($request->filled('student_id'), fn (Builder $q) => $q->where('student_id', (int) $request->input('student_id')))
            ->when($request->filled('search'), fn (Builder $q) => $q->where('name', 'like', '%'.trim((string) $request->input('search')).'%'))
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceDiscountResource::collection($query->get()), 'Daftar diskon berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceDiscountResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar diskon berhasil diambil',
        );
    }

    public function show(FinanceDiscount $discount): JsonResponse
    {
        $discount->load(['feeType', 'program', 'student']);

        return $this->successResponse(new FinanceDiscountResource($discount), 'Detail diskon berhasil diambil');
    }

    public function store(FinanceDiscountRequest $request): JsonResponse
    {
        $discount = FinanceDiscount::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return $this->createdResponse(new FinanceDiscountResource($discount->load(['feeType', 'program', 'student'])), 'Diskon berhasil dibuat');
    }

    public function update(FinanceDiscountRequest $request, FinanceDiscount $discount): JsonResponse
    {
        $discount->update($request->validated());

        return $this->successResponse(new FinanceDiscountResource($discount->fresh(['feeType', 'program', 'student'])), 'Diskon berhasil diperbarui');
    }

    public function destroy(FinanceDiscount $discount): JsonResponse
    {
        $discount->delete();

        return $this->successResponse(null, 'Diskon berhasil dihapus');
    }
}
