<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceVendorRequest;
use App\Http\Resources\FinanceVendorResource;
use App\Models\FinanceVendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Vendor (§34) — penerima pembayaran pengeluaran/pembelian.
 *
 * Tidak boleh dihapus selama masih punya invoice vendor atau PO; nonaktifkan
 * saja agar riwayat transaksi tetap punya rujukan.
 */
class FinanceVendorController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = FinanceVendor::query()
            ->withCount(['invoices', 'purchaseOrders'])
            ->when($request->filled('vendor_type'), fn (Builder $q) => $q->where('vendor_type', $request->string('vendor_type')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $inner) => $inner
                    ->where('code', 'like', "%$term%")
                    ->orWhere('name', 'like', "%$term%")
                    ->orWhere('phone', 'like', "%$term%"));
            })
            ->orderBy('code');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceVendorResource::collection($query->get()), 'Daftar vendor berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceVendorResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => ['types' => FinanceVendor::TYPES, 'type_labels' => FinanceVendor::TYPE_LABELS]]),
            'Daftar vendor berhasil diambil',
        );
    }

    public function show(FinanceVendor $vendor): JsonResponse
    {
        $vendor->loadCount(['invoices', 'purchaseOrders']);

        return $this->successResponse(new FinanceVendorResource($vendor), 'Detail vendor berhasil diambil');
    }

    public function store(FinanceVendorRequest $request): JsonResponse
    {
        $vendor = FinanceVendor::create($request->validated());

        return $this->createdResponse(new FinanceVendorResource($vendor), 'Vendor berhasil dibuat');
    }

    public function update(FinanceVendorRequest $request, FinanceVendor $vendor): JsonResponse
    {
        $vendor->update($request->validated());

        return $this->successResponse(new FinanceVendorResource($vendor->fresh()), 'Vendor berhasil diperbarui');
    }

    public function destroy(FinanceVendor $vendor): JsonResponse
    {
        if ($vendor->invoices()->exists() || $vendor->purchaseOrders()->exists()) {
            throw ValidationException::withMessages([
                'vendor' => 'Vendor masih punya invoice atau PO dan tidak boleh dihapus. Nonaktifkan saja.',
            ]);
        }

        $vendor->delete();

        return $this->successResponse(null, 'Vendor berhasil dihapus');
    }
}
