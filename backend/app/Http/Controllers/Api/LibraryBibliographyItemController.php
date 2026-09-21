<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Library\LibraryItemBulkRequest;
use App\Http\Requests\Library\LibraryItemRequest;
use App\Http\Resources\LibraryItemResource;
use App\Models\LibraryBibliography;
use App\Services\Library\Catalog\CollectionItemService;
use Illuminate\Http\JsonResponse;

/**
 * Eksemplar milik satu bibliographic record.
 *
 * Satu record boleh memiliki 0..N eksemplar (Rule 1), dan setiap eksemplar
 * hanya menunjuk satu record (Rule 2).
 */
class LibraryBibliographyItemController extends BaseApiController
{
    public function __construct(protected CollectionItemService $items)
    {
    }

    public function index(LibraryBibliography $bibliography): JsonResponse
    {
        $items = $bibliography->items()
            ->with(['location:id,code,name,type', 'condition:id,name', 'collectionStatus:id,name'])
            ->orderBy('item_code')
            ->get();

        return $this->successResponse(
            LibraryItemResource::collection($items),
            'Daftar eksemplar berhasil diambil'
        );
    }

    public function store(LibraryItemRequest $request, LibraryBibliography $bibliography): JsonResponse
    {
        $item = $this->items->create($bibliography, $request->validated());

        return $this->createdResponse(
            new LibraryItemResource($item->load(['location:id,code,name', 'condition:id,name', 'bibliography:id,title,slug'])),
            'Eksemplar berhasil dibuat'
        );
    }

    /**
     * Tambah banyak eksemplar sekaligus (§28).
     */
    public function bulk(LibraryItemBulkRequest $request, LibraryBibliography $bibliography): JsonResponse
    {
        $items = $this->items->bulkCreate($bibliography, $request->validated());

        return $this->createdResponse(
            LibraryItemResource::collection($items->load(['location:id,code,name', 'condition:id,name'])),
            count($items).' eksemplar berhasil dibuat'
        );
    }
}