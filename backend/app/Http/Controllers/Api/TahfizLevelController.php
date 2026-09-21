<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizLevelRequest;
use App\Http\Resources\TahfizLevelResource;
use App\Models\TahfizLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Level Tahfiz (Pemula, Tahsin, Juz 30, …).
 *
 * Admin dapat membuat level sendiri, baik yang terikat program maupun level
 * umum. Urutan `order` dipakai untuk menampilkan jenjang secara berurutan.
 */
class TahfizLevelController extends BaseApiController
{
    protected array $filterable = ['program_id', 'status'];

    protected array $counts = ['halaqahs'];

    public function index(Request $request): JsonResponse
    {
        $query = TahfizLevel::query()->with('program')->withCount($this->counts);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value !== null && $value !== '' && in_array($column, $this->filterable, true)) {
                $query->where($column, $value);
            }
        }

        $query->ordered();

        if ($request->boolean('all')) {
            return $this->successResponse(
                TahfizLevelResource::collection($query->get()),
                'Daftar level Tahfiz berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizLevelResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar level Tahfiz berhasil diambil'
        );
    }

    public function show(TahfizLevel $level): JsonResponse
    {
        return $this->successResponse(
            new TahfizLevelResource($level->load('program')->loadCount($this->counts)),
            'Detail level Tahfiz berhasil diambil'
        );
    }

    public function store(TahfizLevelRequest $request): JsonResponse
    {
        $level = TahfizLevel::create($request->validated());

        return $this->createdResponse(
            new TahfizLevelResource($level->load('program')),
            'Level Tahfiz berhasil dibuat'
        );
    }

    public function update(TahfizLevelRequest $request, TahfizLevel $level): JsonResponse
    {
        $data = $request->validated();
        if (($data['code'] ?? null) === $level->code) {
            unset($data['code']);
        }

        $level->update($data);

        return $this->successResponse(
            new TahfizLevelResource($level->load('program')),
            'Level Tahfiz berhasil diperbarui'
        );
    }

    public function destroy(TahfizLevel $level): JsonResponse
    {
        $level->delete();

        return $this->successResponse(null, 'Level Tahfiz berhasil dihapus');
    }
}
