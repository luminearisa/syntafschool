<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizMutabaahItemRequest;
use App\Http\Resources\TahfizMutabaahItemResource;
use App\Models\TahfizMutabaahItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Item mutabaah harian — dikonfigurasi admin, tidak ditulis keras di kode.
 *
 * Dengan ini admin dapat menambah item baru ("Tilawah 1 juz", "Murojaah",
 * "Hafalan baru", "Shalat berjamaah") tanpa perubahan kode.
 */
class TahfizMutabaahItemController extends BaseApiController
{
    protected array $filterable = ['category', 'is_active'];

    public function index(Request $request): JsonResponse
    {
        $query = TahfizMutabaahItem::query();

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value !== null && $value !== '' && in_array($column, $this->filterable, true)) {
                $query->where($column, $value);
            }
        }

        $query->ordered();

        if ($request->boolean('all')) {
            return $this->successResponse(
                TahfizMutabaahItemResource::collection($query->get()),
                'Daftar item mutabaah berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizMutabaahItemResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar item mutabaah berhasil diambil'
        );
    }

    public function show(TahfizMutabaahItem $item): JsonResponse
    {
        return $this->successResponse(
            new TahfizMutabaahItemResource($item),
            'Detail item mutabaah berhasil diambil'
        );
    }

    public function store(TahfizMutabaahItemRequest $request): JsonResponse
    {
        $item = TahfizMutabaahItem::create($request->validated());

        return $this->createdResponse(
            new TahfizMutabaahItemResource($item),
            'Item mutabaah berhasil dibuat'
        );
    }

    public function update(TahfizMutabaahItemRequest $request, TahfizMutabaahItem $item): JsonResponse
    {
        $data = $request->validated();
        if (($data['code'] ?? null) === $item->code) {
            unset($data['code']);
        }

        $item->update($data);

        return $this->successResponse(
            new TahfizMutabaahItemResource($item),
            'Item mutabaah berhasil diperbarui'
        );
    }

    public function destroy(TahfizMutabaahItem $item): JsonResponse
    {
        $item->delete();

        return $this->successResponse(null, 'Item mutabaah berhasil dihapus');
    }
}
