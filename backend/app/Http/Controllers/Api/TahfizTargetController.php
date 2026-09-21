<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizTargetRequest;
use App\Http\Resources\TahfizTargetResource;
use App\Models\TahfizTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Target hafalan default program atau halaqah.
 *
 * Target individual ada di `TahfizIndividualTargetController`.
 */
class TahfizTargetController extends BaseApiController
{
    protected array $filterable = ['scope', 'program_id', 'halaqah_id', 'target_type', 'period', 'is_active'];

    protected array $with = ['program', 'halaqah', 'surah'];

    public function index(Request $request): JsonResponse
    {
        $query = TahfizTarget::query()->with($this->with);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value !== null && $value !== '' && in_array($column, $this->filterable, true)) {
                $query->where($column, $value);
            }
        }

        $query->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                TahfizTargetResource::collection($query->get()),
                'Daftar target berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizTargetResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar target berhasil diambil'
        );
    }

    public function show(TahfizTarget $target): JsonResponse
    {
        return $this->successResponse(
            new TahfizTargetResource($target->load($this->with)),
            'Detail target berhasil diambil'
        );
    }

    public function store(TahfizTargetRequest $request): JsonResponse
    {
        $target = TahfizTarget::create($request->validated());

        return $this->createdResponse(
            new TahfizTargetResource($target->load($this->with)),
            'Target berhasil dibuat.'
        );
    }

    public function update(TahfizTargetRequest $request, TahfizTarget $target): JsonResponse
    {
        $target->update($request->validated());

        return $this->successResponse(
            new TahfizTargetResource($target->load($this->with)),
            'Target berhasil diperbarui.'
        );
    }

    public function destroy(TahfizTarget $target): JsonResponse
    {
        $target->delete();

        return $this->successResponse(null, 'Target berhasil dihapus.');
    }
}
