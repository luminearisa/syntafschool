<?php

namespace App\Modules\UKS\Application\Services;

use App\Models\User;
use App\Modules\UKS\Domain\Enums\StockDirection;
use App\Modules\UKS\Domain\Enums\StockReason;
use App\Modules\UKS\Models\UksMedicine;
use App\Modules\UKS\Models\UksStockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buku persediaan obat UKS.
 *
 * Setiap perubahan stok dicatat sebagai mutasi (masuk/keluar) beserta saldo
 * sesudahnya, dalam satu transaksi berkunci, sehingga stok tidak pernah negatif
 * dan dua pemberian obat bersamaan tidak dapat menghabiskan stok yang sama.
 */
class MedicineStockService
{
    /**
     * @param array{code: string, name: string, category?: ?string, unit?: string, min_stock?: float|int, expiry_date?: ?string, storage_location?: ?string, notes?: ?string, initial_stock?: float|int} $data
     */
    public function create(array $data, User $actor): UksMedicine
    {
        return DB::transaction(function () use ($data, $actor): UksMedicine {
            $medicine = UksMedicine::query()->create([
                'code' => strtoupper(trim($data['code'])),
                'name' => $data['name'],
                'category' => $data['category'] ?? null,
                'unit' => $data['unit'] ?? 'tablet',
                'stock' => 0,
                'min_stock' => $data['min_stock'] ?? 0,
                'expiry_date' => $data['expiry_date'] ?? null,
                'storage_location' => $data['storage_location'] ?? null,
                'notes' => $data['notes'] ?? null,
                'is_active' => true,
            ]);

            $initial = (float) ($data['initial_stock'] ?? 0);
            if ($initial > 0) {
                $this->move($medicine, StockDirection::MASUK, $initial, StockReason::PENGADAAN, $actor, null, 'Stok awal');
            }

            return $medicine->refresh();
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(UksMedicine $medicine, array $data): UksMedicine
    {
        $medicine->fill(array_intersect_key($data, array_flip([
            'name', 'category', 'unit', 'min_stock', 'expiry_date', 'storage_location', 'notes', 'is_active',
        ])));
        $medicine->save();

        return $medicine;
    }

    /**
     * Mutasi stok manual (pengadaan, penyesuaian, kedaluwarsa, rusak).
     */
    public function recordMovement(
        UksMedicine $medicine,
        StockDirection $direction,
        float $quantity,
        StockReason $reason,
        User $actor,
        ?string $notes = null,
        ?string $reference = null,
    ): UksStockMovement {
        return DB::transaction(function () use ($medicine, $direction, $quantity, $reason, $actor, $notes, $reference): UksStockMovement {
            return $this->move($medicine, $direction, $quantity, $reason, $actor, null, $notes, $reference);
        });
    }

    /**
     * Pemakaian obat untuk sebuah kunjungan. Wajib dipanggil di dalam transaksi
     * pemanggilnya; tetap mengunci baris obat.
     */
    public function consumeForVisit(
        UksMedicine $medicine,
        float $quantity,
        int $visitId,
        User $actor,
    ): UksStockMovement {
        return $this->move($medicine, StockDirection::KELUAR, $quantity, StockReason::PEMAKAIAN, $actor, $visitId, null);
    }

    private function move(
        UksMedicine $medicine,
        StockDirection $direction,
        float $quantity,
        StockReason $reason,
        User $actor,
        ?int $visitId,
        ?string $notes,
        ?string $reference = null,
    ): UksStockMovement {
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Jumlah harus lebih dari nol.']);
        }

        /** @var UksMedicine $locked */
        $locked = UksMedicine::query()->whereKey($medicine->id)->lockForUpdate()->firstOrFail();

        if ($locked->isExpired() && $direction === StockDirection::KELUAR && $reason === StockReason::PEMAKAIAN) {
            throw ValidationException::withMessages([
                'medicine_id' => "Obat {$locked->name} sudah kedaluwarsa dan tidak boleh diberikan.",
            ]);
        }

        $current = (float) $locked->stock;
        $after = $direction === StockDirection::MASUK ? $current + $quantity : $current - $quantity;

        if ($after < -0.0001) {
            throw ValidationException::withMessages([
                'quantity' => "Stok {$locked->name} tidak cukup (tersisa {$current} {$locked->unit}).",
            ]);
        }

        $locked->stock = round($after, 2);
        $locked->save();

        return UksStockMovement::query()->create([
            'medicine_id' => $locked->id,
            'direction' => $direction,
            'quantity' => $quantity,
            'stock_after' => $locked->stock,
            'reason' => $reason,
            'visit_id' => $visitId,
            'reference' => $reference,
            'notes' => $notes,
            'recorded_by' => $actor->id,
        ]);
    }
}
