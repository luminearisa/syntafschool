<?php

namespace App\Modules\Clinic\Infrastructure\Providers;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Modules\Clinic\Domain\Contracts\InventoryProviderInterface;
use App\Modules\Clinic\Domain\Enums\InventoryStockResult;
use App\Modules\Clinic\Models\ClinicMedication;
use App\Modules\Clinic\Models\ClinicMedicationBatch;
use Illuminate\Support\Facades\DB;

/**
 * Implementasi Inventory untuk Modul Clinic.
 *
 * Modul Clinic tidak punya buku stok sendiri. Kelas ini adalah satu-satunya
 * jalur yang menyentuh saldo: memakai kunci baris (`lockForUpdate`) supaya dua
 * penyerahan obat bersamaan tidak dapat menghabiskan stok yang sama, mencatat
 * `inventory_movements` sebagai jejak, dan mengembalikan status hasil —
 * bukan melempar pengecualian — agar klinik dapat menampilkan pesan yang tepat.
 *
 * Alasan pemakaian selalu merujuk nomor resep/penyerahan sehingga setiap
 * pengurangan stok dapat ditelusuri kembali ke pasien dan pemberi obat.
 */
class InventoryItemProvider implements InventoryProviderInterface
{
    public function availableQuantity(?int $inventoryItemId): ?float
    {
        if ($inventoryItemId === null) {
            return null;
        }

        $item = InventoryItem::query()->find($inventoryItemId);

        return $item === null ? null : (float) $item->quantity;
    }

    public function usableBatches(int $medicationId): array
    {
        $medication = ClinicMedication::query()->find($medicationId);
        $available = $this->availableQuantity($medication?->inventory_item_id);

        $batches = ClinicMedicationBatch::query()
            ->where('medication_id', $medicationId)
            ->usable()
            ->get();

        return $batches->map(fn (ClinicMedicationBatch $batch): array => [
            'batch_id' => $batch->id,
            'batch_number' => $batch->batch_number,
            'expiry_date' => $batch->expiry_date?->toDateString(),
            // Saldo fisik hanya diketahui Inventory; bila belum dipetakan,
            // pakai jumlah yang tercatat saat penerimaan sebagai perkiraan.
            'quantity' => $available ?? (float) $batch->quantity_received,
        ])->all();
    }

    public function consume(
        ?int $inventoryItemId,
        float $quantity,
        string $reason,
        ?string $reference,
        ?int $actorId,
    ): array {
        if ($inventoryItemId === null) {
            return [
                'result' => InventoryStockResult::NOT_AVAILABLE,
                'movement_id' => null,
                'reference' => null,
                'message' => 'Obat belum dipetakan ke item Inventory, stok tidak dapat dikurangi.',
                'quantity_after' => null,
            ];
        }

        if ($quantity <= 0) {
            return [
                'result' => InventoryStockResult::FAILED,
                'movement_id' => null,
                'reference' => null,
                'message' => 'Jumlah yang dikurangi harus lebih dari nol.',
                'quantity_after' => null,
            ];
        }

        // Kunci baris item: seluruh pemeriksaan saldo di bawah ini memakai nilai
        // yang sudah terkunci sehingga tidak ada balapan antar permintaan.
        $item = InventoryItem::query()->whereKey($inventoryItemId)->lockForUpdate()->first();

        if ($item === null) {
            return [
                'result' => InventoryStockResult::NOT_AVAILABLE,
                'movement_id' => null,
                'reference' => null,
                'message' => 'Item Inventory tidak ditemukan.',
                'quantity_after' => null,
            ];
        }

        if (! $item->is_active) {
            return [
                'result' => InventoryStockResult::FAILED,
                'movement_id' => null,
                'reference' => null,
                'message' => "Item Inventory {$item->name} tidak aktif.",
                'quantity_after' => (float) $item->quantity,
            ];
        }

        $before = (float) $item->quantity;
        $allowNegative = (bool) config('clinic.dispensing.allow_negative_stock', false);
        // Satuan diambil sebagai variabel: operator nullsafe tidak dipakai di
        // dalam interpolasi string agar aman pada semua versi PHP 8.
        $unit = $item->unit?->name ?? 'unit';

        if (! $allowNegative && $before + 0.0001 < $quantity) {
            return [
                'result' => InventoryStockResult::INSUFFICIENT_STOCK,
                'movement_id' => null,
                'reference' => null,
                'message' => "Stok {$item->name} tidak cukup (tersedia {$before} {$unit}).",
                'quantity_after' => $before,
            ];
        }

        $after = round($before - $quantity, 2);

        $item->quantity = $after;
        $item->save();

        $movement = InventoryMovement::query()->create([
            'inventory_item_id' => $item->id,
            'movement_type' => 'OUT',
            'quantity' => $quantity,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'reason' => $reason,
            'reference' => $reference,
            'created_by' => $actorId,
        ]);

        return [
            'result' => InventoryStockResult::SUCCESS,
            'movement_id' => $movement->id,
            'reference' => $reference ?? 'MOV-'.$movement->id,
            'message' => "Stok {$item->name} berkurang {$quantity}; sisa {$after}.",
            'quantity_after' => $after,
        ];
    }

    public function lowStockItems(int $limit = 20): array
    {
        return InventoryItem::query()
            ->where('is_active', true)
            ->whereColumn('quantity', '<=', 'minimum_quantity')
            ->orderBy('quantity')
            ->limit($limit)
            ->get()
            ->map(fn (InventoryItem $item): array => [
                'id' => $item->id,
                'sku' => $item->sku,
                'name' => $item->name,
                'quantity' => (float) $item->quantity,
                'minimum_quantity' => (float) $item->minimum_quantity,
            ])
            ->all();
    }

    public function expiringItems(int $days, int $limit = 20): array
    {
        // Kedaluwarsa obat dicatat per batch di sisi Clinic (Inventory ERP tidak
        // menyimpan tanggal kedaluwarsa per item), jadi sumber data ada di sini.
        $threshold = now()->addDays($days)->toDateString();

        return ClinicMedicationBatch::query()
            ->with('medication:id,code,name,unit')
            ->whereIn('status', ['AVAILABLE', 'QUARANTINED'])
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $threshold)
            ->orderBy('expiry_date')
            ->limit($limit)
            ->get()
            ->map(fn (ClinicMedicationBatch $batch): array => [
                'batch_id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'medication_id' => $batch->medication_id,
                'medication_name' => $batch->medication?->name,
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'expired' => $batch->isExpired(),
                'days_left' => $batch->expiry_date === null ? null : (int) now()->diffInDays($batch->expiry_date, false),
            ])
            ->all();
    }

    /**
     * Dipakai pengujian & pemeriksaan kesehatan: apakah integrasi Inventory
     * siap (tabel ada dan dapat dibaca).
     */
    public function isReachable(): bool
    {
        try {
            DB::table('inventory_items')->limit(1)->count();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
