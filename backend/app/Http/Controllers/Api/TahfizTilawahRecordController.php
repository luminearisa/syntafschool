<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizTilawahRecordRequest;
use App\Http\Resources\TahfizTilawahRecordResource;
use App\Models\TahfizTilawahRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catatan tilawah — membaca Al-Quran, bukan menghafal.
 *
 * Berbeda dengan setoran, tilawah tidak memengaruhi progress hafalan.
 */
class TahfizTilawahRecordController extends BaseApiController
{
    protected array $filterable = ['participant_id', 'enrollment_id', 'surah_id'];

    protected array $with = ['surah', 'participant'];

    public function index(Request $request): JsonResponse
    {
        $query = TahfizTilawahRecord::query()->with($this->with);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value !== null && $value !== '' && in_array($column, $this->filterable, true)) {
                $query->where($column, $value);
            }
        }

        if ($from = $request->query('from')) {
            $query->whereDate('date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('date', '<=', $to);
        }

        $query->latest('date');

        if ($request->boolean('all')) {
            return $this->successResponse(
                TahfizTilawahRecordResource::collection($query->get()),
                'Daftar tilawah berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizTilawahRecordResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar tilawah berhasil diambil'
        );
    }

    public function show(TahfizTilawahRecord $tilawahRecord): JsonResponse
    {
        return $this->successResponse(
            new TahfizTilawahRecordResource($tilawahRecord->load($this->with)),
            'Detail tilawah berhasil diambil'
        );
    }

    public function store(TahfizTilawahRecordRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $payload['recorded_by'] = $request->user()?->id;

        $record = TahfizTilawahRecord::create($payload);

        return $this->createdResponse(
            new TahfizTilawahRecordResource($record->load($this->with)),
            'Tilawah berhasil dicatat.'
        );
    }

    public function update(TahfizTilawahRecordRequest $request, TahfizTilawahRecord $tilawahRecord): JsonResponse
    {
        $tilawahRecord->update($request->validated());

        return $this->successResponse(
            new TahfizTilawahRecordResource($tilawahRecord->load($this->with)),
            'Tilawah berhasil diperbarui.'
        );
    }

    public function destroy(TahfizTilawahRecord $tilawahRecord): JsonResponse
    {
        $tilawahRecord->delete();

        return $this->successResponse(null, 'Tilawah berhasil dihapus.');
    }
}
