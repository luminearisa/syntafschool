<?php

namespace App\Modules\Asset\Infrastructure\Providers;

use App\Modules\Asset\Domain\Contracts\InventoryProviderInterface;
use App\Modules\Asset\Infrastructure\Support\IntegrationOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jembatan ke modul Persediaan.
 *
 * Suku cadang TIDAK disimpan dua kali di modul Asset: pengeluaran barang selalu
 * mengurangi stok Persediaan dan meninggalkan satu pergerakan. Bila tabel
 * Persediaan belum ada, permintaan dicatat sebagai PENDING dan pekerjaan
 * pemeliharaan menunggu suku cadang — bukan menganggap barang tersedia.
 */
class EloquentInventoryProvider implements InventoryProviderInterface
{
    public function __construct(private readonly IntegrationOutbox $outbox) {}

    /**
     * Cari barang menurut kode, SKU, atau barcode — mana pun yang tersedia pada
     * tabel persediaan. Nama kolom kode berbeda antar-modul, dan modul ini tidak
     * boleh gagal hanya karena itu.
     */
    protected function findItem(string $code, bool $lock = false): ?object
    {
        if (! Schema::hasTable('inventory_items')) {
            return null;
        }

        $columns = $this->columns('inventory_items');
        $query = DB::table('inventory_items')->where(function ($inner) use ($columns, $code): void {
            foreach (['code', 'sku', 'barcode', 'serial_number'] as $column) {
                if (in_array($column, $columns, true)) {
                    $inner->orWhere($column, $code);
                }
            }
        });

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function item(string $code): ?array
    {
        if (! Schema::hasTable('inventory_items')) {
            return null;
        }

        $item = $this->findItem($code) ?? DB::table('inventory_items')->where('name', $code)->first();

        return $item === null ? null : (array) $item;
    }

    public function issue(array $payload): array
    {
        $code = (string) ($payload['code'] ?? '');
        $quantity = (float) ($payload['quantity'] ?? 1);

        if (! Schema::hasTable('inventory_items')) {
            return ['status' => 'PENDING', 'reference' => (string) $this->outbox->push('inventory', 'issue', $payload)->id];
        }

        return DB::transaction(function () use ($code, $quantity, $payload): array {
            $item = $this->findItem($code, true);

            if ($item === null) {
                return ['status' => 'NOT_FOUND', 'reason' => 'Barang '.$code.' tidak ada di persediaan.'];
            }

            $available = (float) ($item->quantity ?? 0);

            if ($available < $quantity) {
                // Stok kurang: pekerjaan menunggu, bukan dipaksa selesai.
                return [
                    'status' => 'INSUFFICIENT',
                    'reason' => sprintf('Stok %s hanya %s, diminta %s.', $code, $available, $quantity),
                    'available' => $available,
                ];
            }

            DB::table('inventory_items')->where('id', $item->id)->update(['quantity' => $available - $quantity]);

            $unitCost = (float) ($item->unit_cost ?? 0);
            $total = number_format($unitCost * $quantity, 2, '.', '');

            $movementId = $this->writeMovement([
                'inventory_item_id' => $item->id,
                'item_id' => $item->id,
                'type' => 'OUT',
                'direction' => 'OUT',
                'movement_type' => 'out',
                'quantity' => $quantity,
                // Jumlah sebelum/sesudah wajib pada tabel persediaan: tanpa itu,
                // riwayat stok tidak dapat direkonstruksi (dan kolomnya NOT NULL).
                'quantity_before' => $available,
                'quantity_after' => $available - $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $total,
                'reference' => $payload['reference'] ?? null,
                'reason' => 'Pengeluaran suku cadang untuk work order '.($payload['reference'] ?? '-'),
                'note' => 'Pengeluaran untuk work order '.($payload['reference'] ?? '-'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'status' => 'ISSUED',
                'item_id' => $item->id,
                'quantity' => $quantity,
                'unit_cost' => number_format($unitCost, 2, '.', ''),
                'total_cost' => $total,
                'movement_id' => $movementId,
                'remaining' => $available - $quantity,
            ];
        });
    }

    public function receive(array $payload): array
    {
        if (! Schema::hasTable('inventory_items')) {
            return ['status' => 'PENDING', 'reference' => (string) $this->outbox->push('inventory', 'receive', $payload)->id];
        }

        $code = (string) ($payload['code'] ?? '');
        $quantity = (float) ($payload['quantity'] ?? 1);

        $item = $this->findItem($code);

        if ($item === null) {
            return ['status' => 'NOT_FOUND', 'reason' => 'Barang '.$code.' tidak ada di persediaan.'];
        }

        DB::table('inventory_items')->where('id', $item->id)->update(['quantity' => (float) ($item->quantity ?? 0) + $quantity]);

        $sebelum = (float) ($item->quantity ?? 0);

        $movementId = $this->writeMovement([
            'inventory_item_id' => $item->id,
            'item_id' => $item->id,
            'type' => 'IN',
            'direction' => 'IN',
            'movement_type' => 'in',
            'quantity' => $quantity,
            'quantity_before' => $sebelum,
            'quantity_after' => $sebelum + $quantity,
            'reference' => $payload['reference'] ?? null,
            'reason' => 'Pengembalian barang dari work order '.($payload['reference'] ?? '-'),
            'note' => 'Pengembalian barang dari work order '.($payload['reference'] ?? '-'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['status' => 'RECEIVED', 'item_id' => $item->id, 'quantity' => $quantity, 'movement_id' => $movementId];
    }

    public function recordConsumable(array $payload): array
    {
        if (! Schema::hasTable('inventory_items')) {
            return ['status' => 'PENDING', 'reference' => (string) $this->outbox->push('inventory', 'consumable', $payload)->id];
        }

        $name = (string) ($payload['name'] ?? 'Barang habis pakai');
        $quantity = (float) ($payload['quantity'] ?? 1);

        $existing = DB::table('inventory_items')
            ->when(isset($payload['category']), fn ($q) => $q->where('name', $name))
            ->first();

        if ($existing !== null) {
            DB::table('inventory_items')->where('id', $existing->id)->update(['quantity' => (float) ($existing->quantity ?? 0) + $quantity]);

            return ['status' => 'RECORDED', 'item_id' => $existing->id, 'quantity' => $quantity];
        }

        $columns = $this->columns('inventory_items');
        $code = $payload['serial_number'] ?? ('BH-'.strtoupper(substr(md5($name), 0, 6)));

        // `sku`/`code` bersifat wajib pada tabel persediaan; nilai apa pun yang
        // tersedia dipakai supaya pencatatan barang habis pakai tidak gagal hanya
        // karena perbedaan nama kolom antar-modul.
        $insert = array_intersect_key([
            'name' => $name,
            'sku' => $code,
            'code' => $code,
            'quantity' => $quantity,
            'unit_cost' => $payload['unit_cost'] ?? '0.00',
            'minimum_quantity' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], array_flip($columns));

        $id = DB::table('inventory_items')->insertGetId($insert);

        return ['status' => 'RECORDED', 'item_id' => $id, 'quantity' => $quantity];
    }

    /** Tulis pergerakan stok hanya pada kolom yang benar-benar ada. */
    protected function writeMovement(array $data): ?int
    {
        if (! Schema::hasTable('inventory_movements')) {
            return null;
        }

        $columns = $this->columns('inventory_movements');
        $insert = array_intersect_key($data, array_flip($columns));

        return $insert === [] ? null : DB::table('inventory_movements')->insertGetId($insert);
    }

    /** @return list<string> */
    protected function columns(string $table): array
    {
        return Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
    }
}
