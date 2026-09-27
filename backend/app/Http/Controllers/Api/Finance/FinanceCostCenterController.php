<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceCostCenterRequest;
use App\Http\Resources\FinanceCostCenterResource;
use App\Models\FinanceCashAccount;
use App\Models\FinanceBankAccount;
use App\Models\FinanceBudget;
use App\Models\FinanceCostCenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Cost center (§7) — unit pengukur pendapatan/pengeluaran/anggaran.
 *
 * Tidak boleh dihapus selama masih menaungi anggaran, kas, atau rekening bank:
 * menghapusnya akan membuat laporan per unit kehilangan induknya.
 */
class FinanceCostCenterController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = FinanceCostCenter::query()
            ->with('manager')
            ->withCount('budgets')
            ->when($request->filled('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $inner) => $inner
                    ->where('code', 'like', "%$term%")
                    ->orWhere('name', 'like', "%$term%"));
            })
            ->orderBy('code');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceCostCenterResource::collection($query->get()), 'Daftar cost center berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceCostCenterResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar cost center berhasil diambil',
        );
    }

    public function show(FinanceCostCenter $costCenter): JsonResponse
    {
        $costCenter->load(['parent', 'children', 'manager'])->loadCount('budgets');

        return $this->successResponse(new FinanceCostCenterResource($costCenter), 'Detail cost center berhasil diambil');
    }

    public function store(FinanceCostCenterRequest $request): JsonResponse
    {
        $costCenter = FinanceCostCenter::create($request->validated());

        return $this->createdResponse(new FinanceCostCenterResource($costCenter), 'Cost center berhasil dibuat');
    }

    public function update(FinanceCostCenterRequest $request, FinanceCostCenter $costCenter): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['parent_id']) && (int) $data['parent_id'] === (int) $costCenter->id) {
            throw ValidationException::withMessages(['parent_id' => 'Cost center tidak boleh menjadi induk dirinya sendiri.']);
        }

        $costCenter->update($data);

        return $this->successResponse(new FinanceCostCenterResource($costCenter->fresh()), 'Cost center berhasil diperbarui');
    }

    public function destroy(FinanceCostCenter $costCenter): JsonResponse
    {
        if ($costCenter->children()->exists()) {
            throw ValidationException::withMessages([
                'cost_center' => 'Pindahkan atau hapus cost center anak sebelum menghapus induknya.',
            ]);
        }

        $usedBy = [];
        if (FinanceBudget::query()->where('cost_center_id', $costCenter->id)->exists()) {
            $usedBy[] = 'anggaran';
        }
        if (FinanceCashAccount::query()->where('cost_center_id', $costCenter->id)->exists()) {
            $usedBy[] = 'akun kas';
        }
        if (FinanceBankAccount::query()->where('cost_center_id', $costCenter->id)->exists()) {
            $usedBy[] = 'rekening bank';
        }

        if ($usedBy !== []) {
            throw ValidationException::withMessages([
                'cost_center' => 'Cost center masih dipakai oleh '.implode(', ', $usedBy).' dan tidak boleh dihapus. Nonaktifkan saja.',
            ]);
        }

        $costCenter->delete();

        return $this->successResponse(null, 'Cost center berhasil dihapus');
    }
}
