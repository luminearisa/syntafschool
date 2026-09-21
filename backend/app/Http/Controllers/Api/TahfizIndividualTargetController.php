<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizIndividualTargetRequest;
use App\Http\Resources\TahfizIndividualTargetResource;
use App\Models\TahfizIndividualTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Target hafalan per peserta.
 *
 * Kecepatan hafalan setiap peserta berbeda — target individual mengambil
 * prioritas tertinggi saat progress engine menyelesaikan target yang berlaku.
 */
class TahfizIndividualTargetController extends BaseApiController
{
    protected array $filterable = [
        'participant_id', 'enrollment_id', 'period', 'target_type', 'is_active',
    ];

    protected array $with = ['participant', 'surah'];

    public function index(Request $request): JsonResponse
    {
        $query = TahfizIndividualTarget::query()->with($this->with);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value !== null && $value !== '' && in_array($column, $this->filterable, true)) {
                $query->where($column, $value);
            }
        }

        $query->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                TahfizIndividualTargetResource::collection($query->get()),
                'Daftar target individual berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizIndividualTargetResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar target individual berhasil diambil'
        );
    }

    public function show(TahfizIndividualTarget $individualTarget): JsonResponse
    {
        return $this->successResponse(
            new TahfizIndividualTargetResource($individualTarget->load($this->with)),
            'Detail target individual berhasil diambil'
        );
    }

    public function store(TahfizIndividualTargetRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $payload['created_by'] = $request->user()?->id;

        $target = TahfizIndividualTarget::create($payload);

        return $this->createdResponse(
            new TahfizIndividualTargetResource($target->load($this->with)),
            'Target individual berhasil dibuat.'
        );
    }

    public function update(TahfizIndividualTargetRequest $request, TahfizIndividualTarget $individualTarget): JsonResponse
    {
        $individualTarget->update($request->validated());

        return $this->successResponse(
            new TahfizIndividualTargetResource($individualTarget->load($this->with)),
            'Target individual berhasil diperbarui.'
        );
    }

    public function destroy(TahfizIndividualTarget $individualTarget): JsonResponse
    {
        $individualTarget->delete();

        return $this->successResponse(null, 'Target individual berhasil dihapus.');
    }
}
