<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ItemCategory;
use App\Models\StorageLocation;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Inventaris sekolah: master aset/barang dan pergerakan stok yang dapat diaudit.
 * Saldo stok hanya berubah lewat pembuatan awal atau endpoint adjustment.
 */
class InventoryController extends BaseApiController
{
    private const RELATIONS = ['category', 'unit', 'storageLocation.room'];

    public function index(Request $request): JsonResponse
    {
        $query = InventoryItem::query()
            ->with(self::RELATIONS)
            ->when($request->filled('category_id'), fn (Builder $q) => $q->where('item_category_id', (int) $request->input('category_id')))
            ->when($request->filled('location_id'), fn (Builder $q) => $q->where('storage_location_id', (int) $request->input('location_id')))
            ->when($request->filled('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->boolean('low_stock'), fn (Builder $q) => $q->whereColumn('quantity', '<=', 'minimum_quantity'))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $inner) => $inner
                    ->where('sku', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhere('serial_number', 'like', "%{$term}%"));
            })
            ->orderBy('name');

        if ($request->boolean('all')) {
            return $this->successResponse(InventoryItemResource::collection($query->get()), 'Daftar inventaris berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            InventoryItemResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar inventaris berhasil diambil',
        );
    }

    public function lookups(): JsonResponse
    {
        return $this->successResponse([
            'categories' => ItemCategory::query()->orderBy('name')->get(['id', 'name', 'type']),
            'units' => Unit::query()->orderBy('name')->get(['id', 'name', 'symbol']),
            'locations' => StorageLocation::query()->with('room:id,name')->orderBy('name')->get(['id', 'name', 'room_id']),
        ], 'Referensi inventaris berhasil diambil');
    }

    public function show(InventoryItem $item): JsonResponse
    {
        $item->load([
            ...self::RELATIONS,
            'movements.createdBy',
        ]);

        return $this->successResponse(new InventoryItemResource($item), 'Detail inventaris berhasil diambil');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedItem($request, creating: true);
        $initialQuantity = round((float) ($data['quantity'] ?? 0), 2);
        unset($data['quantity']);

        $item = DB::transaction(function () use ($data, $initialQuantity, $request): InventoryItem {
            $item = InventoryItem::create($data + ['quantity' => $initialQuantity]);

            if ($initialQuantity > 0) {
                InventoryMovement::create([
                    'inventory_item_id' => $item->id,
                    'movement_type' => 'opening',
                    'quantity' => $initialQuantity,
                    'quantity_before' => 0,
                    'quantity_after' => $initialQuantity,
                    'reason' => 'Saldo awal saat barang dibuat',
                    'created_by' => $request->user()?->id,
                ]);
            }

            return $item;
        });

        return $this->createdResponse(
            new InventoryItemResource($item->load(self::RELATIONS)),
            'Barang inventaris berhasil dibuat',
        );
    }

    public function update(Request $request, InventoryItem $item): JsonResponse
    {
        // Jumlah stok tidak dapat ditulis lewat form edit; gunakan endpoint adjustment
        // agar setiap penerimaan/pengeluaran mempunyai alasan dan jejak audit.
        $item->update($this->validatedItem($request, creating: false, item: $item));

        return $this->successResponse(
            new InventoryItemResource($item->fresh(self::RELATIONS)),
            'Barang inventaris berhasil diperbarui',
        );
    }

    public function adjust(Request $request, InventoryItem $item): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in(['in', 'out'])],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'reason' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:120'],
        ], [], [
            'direction' => 'jenis pergerakan',
            'quantity' => 'jumlah',
            'reason' => 'alasan',
            'reference' => 'referensi',
        ]);

        $updated = DB::transaction(function () use ($data, $item, $request): InventoryItem {
            $locked = InventoryItem::query()->lockForUpdate()->findOrFail($item->id);
            $before = round((float) $locked->quantity, 2);
            $quantity = round((float) $data['quantity'], 2);
            $after = $data['direction'] === 'in' ? $before + $quantity : $before - $quantity;

            if ($after < -0.0001) {
                throw ValidationException::withMessages([
                    'quantity' => 'Stok tidak mencukupi untuk pengeluaran ini.',
                ]);
            }

            $after = max(0, round($after, 2));
            $locked->update(['quantity' => $after]);

            InventoryMovement::create([
                'inventory_item_id' => $locked->id,
                'movement_type' => $data['direction'],
                'quantity' => $quantity,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'reason' => $data['reason'],
                'reference' => $data['reference'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            return $locked->fresh(self::RELATIONS);
        });

        return $this->successResponse(new InventoryItemResource($updated), 'Stok inventaris berhasil disesuaikan');
    }

    public function destroy(InventoryItem $item): JsonResponse
    {
        if ((float) $item->quantity > 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Stok harus nol sebelum barang dapat diarsipkan.',
            ]);
        }

        $item->delete();

        return $this->successResponse(null, 'Barang inventaris berhasil diarsipkan');
    }

    /** @return array<string, mixed> */
    private function validatedItem(Request $request, bool $creating, ?InventoryItem $item = null): array
    {
        $skuRule = Rule::unique('inventory_items', 'sku')->ignore($item?->id);
        $required = $creating ? 'required' : 'sometimes';

        $rules = [
            'sku' => [$required, 'string', 'max:50', $skuRule],
            'name' => [$required, 'string', 'max:160'],
            'item_category_id' => ['nullable', 'integer', 'exists:item_categories,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'storage_location_id' => ['nullable', 'integer', 'exists:storage_locations,id'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'minimum_quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'purchase_date' => ['nullable', 'date'],
            'condition' => ['nullable', Rule::in(['good', 'needs_repair', 'damaged', 'unusable'])],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];

        if ($creating) {
            $rules['quantity'] = ['nullable', 'numeric', 'min:0', 'max:9999999999'];
        }

        return $request->validate($rules, [], [
            'sku' => 'kode barang',
            'name' => 'nama barang',
            'item_category_id' => 'kategori',
            'unit_id' => 'satuan',
            'storage_location_id' => 'lokasi penyimpanan',
            'minimum_quantity' => 'batas minimum stok',
            'unit_cost' => 'harga satuan',
            'purchase_date' => 'tanggal pembelian',
            'condition' => 'kondisi barang',
        ]);
    }
}
