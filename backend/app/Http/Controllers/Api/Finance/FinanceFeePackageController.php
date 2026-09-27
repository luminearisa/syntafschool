<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceFeePackageRequest;
use App\Http\Resources\FinanceFeePackageResource;
use App\Models\FinanceFeeItem;
use App\Models\FinanceFeePackage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paket tagihan (§11, §12) beserta baris itemnya.
 *
 * Paket + item disimpan atomik dalam satu transaksi database: setengah tersimpan
 * akan membuat total paket salah hitung. Item disinkronkan (upsert yang dikirim,
 * hapus yang tidak ada) agar satu jenis tagihan tidak dobel dalam satu paket.
 */
class FinanceFeePackageController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = FinanceFeePackage::query()
            ->with(['program', 'schoolClass'])
            ->withCount('items')
            ->when($request->filled('boarding_type'), fn (Builder $q) => $q->where('boarding_type', $request->string('boarding_type')))
            ->when($request->filled('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('academic_year'), fn (Builder $q) => $q->where('academic_year', (int) $request->input('academic_year')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $inner) => $inner
                    ->where('code', 'like', "%$term%")
                    ->orWhere('name', 'like', "%$term%"));
            })
            ->orderBy('code');

        if ($request->boolean('all')) {
            return $this->successResponse($this->withTotals($query->get()), 'Daftar paket tagihan berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));
        $page = $query->paginate($perPage)->appends($request->query());

        return $this->successResponse(
            $this->withTotals($page)->additional(['meta' => ['boarding_types' => FinanceFeePackage::BOARDING_TYPES]]),
            'Daftar paket tagihan berhasil diambil',
        );
    }

    public function show(FinanceFeePackage $feePackage): JsonResponse
    {
        $feePackage->load(['program', 'schoolClass', 'items.feeType'])->loadCount('items');
        $feePackage->setAttribute('total_amount', $feePackage->total());

        return $this->successResponse(new FinanceFeePackageResource($feePackage), 'Detail paket tagihan berhasil diambil');
    }

    public function store(FinanceFeePackageRequest $request): JsonResponse
    {
        $data = $request->validated();
        $items = $data['items'] ?? [];
        unset($data['items']);

        $this->assertUniqueItems($items);

        $package = DB::transaction(function () use ($data, $items): FinanceFeePackage {
            $package = FinanceFeePackage::create($data);
            $this->syncItems($package, $items);

            return $package;
        });

        $package->load(['program', 'schoolClass', 'items.feeType'])->loadCount('items');
        $package->setAttribute('total_amount', $package->total());

        return $this->createdResponse(new FinanceFeePackageResource($package), 'Paket tagihan berhasil dibuat');
    }

    public function update(FinanceFeePackageRequest $request, FinanceFeePackage $feePackage): JsonResponse
    {
        $data = $request->validated();
        $items = $data['items'] ?? null;
        unset($data['items']);

        if ($items !== null) {
            $this->assertUniqueItems($items);
        }

        DB::transaction(function () use ($feePackage, $data, $items): void {
            $feePackage->update($data);
            if ($items !== null) {
                $this->syncItems($feePackage, $items);
            }
        });

        $feePackage->load(['program', 'schoolClass', 'items.feeType'])->loadCount('items');
        $feePackage->setAttribute('total_amount', $feePackage->total());

        return $this->successResponse(new FinanceFeePackageResource($feePackage), 'Paket tagihan berhasil diperbarui');
    }

    public function destroy(FinanceFeePackage $feePackage): JsonResponse
    {
        DB::transaction(function () use ($feePackage): void {
            $feePackage->items()->delete();
            $feePackage->delete();
        });

        return $this->successResponse(null, 'Paket tagihan berhasil dihapus');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FinanceFeePackage>|FinanceFeePackage[]  $packages
     * @return \Illuminate\Http\Resources\Json\ResourceCollection
     */
    protected function withTotals($packages)
    {
        foreach ($packages as $package) {
            $package->setAttribute('total_amount', $package->total());
        }

        return FinanceFeePackageResource::collection($packages);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    protected function syncItems(FinanceFeePackage $package, array $items): void
    {
        $keepIds = [];

        foreach ($items as $item) {
            $payload = [
                'fee_package_id' => $package->id,
                'fee_type_id' => (int) $item['fee_type_id'],
                'amount' => round((float) ($item['amount'] ?? 0), 2),
                'frequency' => $item['frequency'] ?? $package->defaultFrequencyFor((int) $item['fee_type_id']),
                'is_active' => (bool) ($item['is_active'] ?? true),
            ];

            $id = $item['id'] ?? null;
            if ($id !== null) {
                $existing = FinanceFeeItem::query()->where('fee_package_id', $package->id)->find($id);
                if ($existing !== null) {
                    $existing->update($payload);
                    $keepIds[] = $existing->id;

                    continue;
                }
            }

            // Tanpa id eksplisit: cocokkan berdasarkan jenis tagihan agar update
            // tidak menyisipkan baris duplikat (satu fee_type unik per paket).
            $byType = FinanceFeeItem::query()
                ->where('fee_package_id', $package->id)
                ->where('fee_type_id', $payload['fee_type_id'])
                ->first();

            if ($byType !== null) {
                $byType->update($payload);
                $keepIds[] = $byType->id;

                continue;
            }

            $keepIds[] = FinanceFeeItem::create($payload)->id;
        }

        // Hapus baris yang tidak lagi dikirim (sinkron penuh).
        $package->items()->whereNotIn('id', $keepIds)->delete();
    }

    /** Satu jenis tagihan tidak boleh muncul dua kali dalam satu paket. */
    protected function assertUniqueItems(array $items): void
    {
        $seen = [];
        foreach ($items as $item) {
            $id = (int) ($item['fee_type_id'] ?? 0);
            if (in_array($id, $seen, true)) {
                throw ValidationException::withMessages([
                    'items' => 'Jenis tagihan tidak boleh dobel dalam satu paket.',
                ]);
            }
            $seen[] = $id;
        }
    }
}
