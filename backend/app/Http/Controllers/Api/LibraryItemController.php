<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Library\LibraryItemRequest;
use App\Http\Resources\LibraryItemResource;
use App\Models\LibraryItem;
use App\Services\Library\Catalog\CollectionItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Eksemplar koleksi (daftar lintas record).
 *
 * Item code, nomor inventaris, dan barcode dibangkitkan dari konfigurasi
 * penomoran perpustakaan; RFID hanya data (tanpa integrasi perangkat).
 */
class LibraryItemController extends BaseApiController
{
    public function __construct(protected CollectionItemService $items)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->items->query($request);

        if ($request->boolean('all')) {
            return $this->successResponse(
                LibraryItemResource::collection($query->get()),
                'Daftar eksemplar berhasil diambil'
            );
        }

        return $this->successResponse(
            LibraryItemResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar eksemplar berhasil diambil'
        );
    }

    public function show(LibraryItem $item): JsonResponse
    {
        $item->load([
            'bibliography:id,title,slug,control_number,publisher_id',
            'library:id,code,name',
            'location:id,code,name,type',
            'condition:id,name',
            'collectionStatus:id,name',
            'acquisitionSource:id,name',
        ]);

        return $this->successResponse(
            new LibraryItemResource($item),
            'Detail eksemplar berhasil diambil'
        );
    }

    public function update(LibraryItemRequest $request, LibraryItem $item): JsonResponse
    {
        $item = $this->items->update($item, $request->validated());

        return $this->successResponse(
            new LibraryItemResource($item->load(['location:id,code,name', 'condition:id,name', 'collectionStatus:id,name'])),
            'Eksemplar berhasil diperbarui'
        );
    }

    /**
     * Ubah status eksemplar (beserta jejak audit ITEM_STATUS_CHANGED).
     */
    public function setStatus(Request $request, LibraryItem $item): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', LibraryItem::STATUSES)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $item = $this->items->setStatus($item, $validated['status'], $validated['reason'] ?? null);

        return $this->successResponse(
            new LibraryItemResource($item->load(['collectionStatus:id,name'])),
            'Status eksemplar berhasil diubah'
        );
    }

    public function destroy(LibraryItem $item): JsonResponse
    {
        $this->items->delete($item);

        return $this->successResponse(null, 'Eksemplar berhasil dihapus');
    }

    /**
     * Aksi massal: pindah lokasi, ubah kondisi, ubah status (§56).
     */
    public function bulkAssign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'location_id' => ['nullable', 'integer', 'exists:library_locations,id'],
            'condition_id' => ['nullable', 'integer', 'exists:library_collection_conditions,id'],
            'status_id' => ['nullable', 'integer', 'exists:library_collection_statuses,id'],
            'status' => ['nullable', 'string', 'in:'.implode(',', LibraryItem::STATUSES)],
        ]);

        $updated = $this->items->bulkAssign($validated['ids'], $validated);

        return $this->successResponse(['updated' => $updated], "{$updated} eksemplar berhasil diperbarui");
    }

    /**
     * Bangkitkan barcode otomatis untuk eksemplar yang belum punya (§56).
     */
    public function bulkGenerateBarcode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $generated = $this->items->bulkGenerateBarcode($validated['ids']);

        return $this->successResponse(
            ['generated' => $generated],
            "{$generated} barcode berhasil dibangkitkan"
        );
    }
}