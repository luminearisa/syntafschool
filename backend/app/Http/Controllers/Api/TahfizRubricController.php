<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizRubricRequest;
use App\Http\Resources\TahfizRubricResource;
use App\Models\TahfizRubric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rubrik penilaian Tahfiz (tahsin, tilawah, hafalan, placement, exam, tasmi).
 *
 * Kriteria disimpan bersama rubrik dalam satu permintaan. Saat memperbarui,
 * seluruh kriteria lama diganti (sync) agar tidak ada kriteria yatim — namun
 * kriteria yang sudah dipakai untuk menilai tetap dirujuk lewat
 * `tahfiz_tahsin_scores.criterion` (label disimpan sebagai cadangan).
 */
class TahfizRubricController extends BaseApiController
{
    protected array $filterable = ['scope', 'status'];

    public function index(Request $request): JsonResponse
    {
        $query = TahfizRubric::query()->with('items');

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value !== null && $value !== '' && in_array($column, $this->filterable, true)) {
                $query->where($column, $value);
            }
        }

        $query->orderBy('name');

        if ($request->boolean('all')) {
            return $this->successResponse(
                TahfizRubricResource::collection($query->get()),
                'Daftar rubrik penilaian berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizRubricResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar rubrik penilaian berhasil diambil'
        );
    }

    public function show(TahfizRubric $rubric): JsonResponse
    {
        return $this->successResponse(
            new TahfizRubricResource($rubric->load('items')),
            'Detail rubrik penilaian berhasil diambil'
        );
    }

    public function store(TahfizRubricRequest $request): JsonResponse
    {
        $data = $request->validated();
        $items = $data['items'] ?? [];
        unset($data['items']);

        $rubric = DB::transaction(function () use ($data, $items) {
            $rubric = TahfizRubric::create($data);
            $this->syncItems($rubric, $items);

            return $rubric;
        });

        return $this->createdResponse(
            new TahfizRubricResource($rubric->load('items')),
            'Rubrik penilaian berhasil dibuat'
        );
    }

    public function update(TahfizRubricRequest $request, TahfizRubric $rubric): JsonResponse
    {
        $data = $request->validated();
        $items = $data['items'] ?? [];
        unset($data['items']);

        DB::transaction(function () use ($rubric, $data, $items) {
            $rubric->update($data);
            $this->syncItems($rubric, $items);
        });

        return $this->successResponse(
            new TahfizRubricResource($rubric->load('items')),
            'Rubrik penilaian berhasil diperbarui'
        );
    }

    public function destroy(TahfizRubric $rubric): JsonResponse
    {
        $rubric->delete();

        return $this->successResponse(null, 'Rubrik penilaian berhasil dihapus');
    }

    /**
     * Ganti seluruh kriteria rubrik.
     *
     * @param array<int, array<string, mixed>> $items
     */
    protected function syncItems(TahfizRubric $rubric, array $items): void
    {
        if ($items === []) {
            return;
        }

        $rubric->items()->delete();

        foreach ($items as $index => $item) {
            $rubric->items()->create([
                'criterion' => $item['criterion'],
                'weight' => $item['weight'] ?? 1,
                'order' => $item['order'] ?? ($index + 1),
            ]);
        }
    }
}
