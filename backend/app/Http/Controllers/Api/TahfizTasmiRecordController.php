<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizTasmiRecordRequest;
use App\Http\Resources\TahfizTasmiRecordResource;
use App\Models\TahfizTasmiRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catatan tasmi' — setoran hafalan dalam jumlah besar (1/5/10/30 juz)
 * di hadapan penguji.
 */
class TahfizTasmiRecordController extends BaseApiController
{
    protected array $filterable = ['participant_id', 'enrollment_id', 'examiner_id', 'result'];

    protected array $with = ['participant', 'examiner'];

    public function index(Request $request): JsonResponse
    {
        $query = TahfizTasmiRecord::query()->with($this->with);

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
                TahfizTasmiRecordResource::collection($query->get()),
                'Daftar tasmi\' berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizTasmiRecordResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar tasmi\' berhasil diambil'
        );
    }

    public function show(TahfizTasmiRecord $tasmiRecord): JsonResponse
    {
        return $this->successResponse(
            new TahfizTasmiRecordResource($tasmiRecord->load($this->with)),
            'Detail tasmi\' berhasil diambil'
        );
    }

    public function store(TahfizTasmiRecordRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $payload['recorded_by'] = $request->user()?->id;

        $record = TahfizTasmiRecord::create($payload);

        return $this->createdResponse(
            new TahfizTasmiRecordResource($record->load($this->with)),
            'Tasmi\' berhasil dicatat.'
        );
    }

    public function update(TahfizTasmiRecordRequest $request, TahfizTasmiRecord $tasmiRecord): JsonResponse
    {
        $tasmiRecord->update($request->validated());

        return $this->successResponse(
            new TahfizTasmiRecordResource($tasmiRecord->load($this->with)),
            'Tasmi\' berhasil diperbarui.'
        );
    }

    public function destroy(TahfizTasmiRecord $tasmiRecord): JsonResponse
    {
        $tasmiRecord->delete();

        return $this->successResponse(null, 'Tasmi\' berhasil dihapus.');
    }
}
