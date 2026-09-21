<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizRevisionRecordRequest;
use App\Http\Resources\TahfizRevisionRecordResource;
use App\Models\TahfizRevisionRecord;
use App\Services\Tahfiz\ProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catatan murojaah — pengulangan hafalan lama agar mutqin.
 *
 * Murojaah memengaruhi progress engine (murojaah_count & tanggal terakhir),
 * sehingga setiap perubahan memicu perhitungan ulang.
 */
class TahfizRevisionRecordController extends BaseApiController
{
    protected array $filterable = ['participant_id', 'enrollment_id', 'halaqah_id', 'method'];

    protected array $with = ['surah', 'participant', 'halaqah'];

    public function __construct(protected ProgressService $progress)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = TahfizRevisionRecord::query()->with($this->with);

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
                TahfizRevisionRecordResource::collection($query->get()),
                'Daftar murojaah berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizRevisionRecordResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar murojaah berhasil diambil'
        );
    }

    public function show(TahfizRevisionRecord $revisionRecord): JsonResponse
    {
        return $this->successResponse(
            new TahfizRevisionRecordResource($revisionRecord->load($this->with)),
            'Detail murojaah berhasil diambil'
        );
    }

    public function store(TahfizRevisionRecordRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $payload['recorded_by'] = $request->user()?->id;

        $record = TahfizRevisionRecord::create($payload);
        $this->progress->onRevisionSaved($record);

        return $this->createdResponse(
            new TahfizRevisionRecordResource($record->load($this->with)),
            'Murojaah berhasil dicatat.'
        );
    }

    public function update(TahfizRevisionRecordRequest $request, TahfizRevisionRecord $revisionRecord): JsonResponse
    {
        $revisionRecord->update($request->validated());
        $this->progress->onRevisionSaved($revisionRecord);

        return $this->successResponse(
            new TahfizRevisionRecordResource($revisionRecord->load($this->with)),
            'Murojaah berhasil diperbarui.'
        );
    }

    public function destroy(TahfizRevisionRecord $revisionRecord): JsonResponse
    {
        $revisionRecord->delete();
        $this->progress->onRevisionDeleted($revisionRecord);

        return $this->successResponse(null, 'Murojaah berhasil dihapus.');
    }
}
