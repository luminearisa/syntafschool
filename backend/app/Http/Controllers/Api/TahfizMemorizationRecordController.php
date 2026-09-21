<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizMemorizationRecordRequest;
use App\Http\Requests\Tahfiz\TahfizMemorizationReviewRequest;
use App\Http\Resources\TahfizMemorizationRecordResource;
use App\Models\TahfizMemorizationMistake;
use App\Models\TahfizMemorizationRecord;
use App\Services\Tahfiz\ProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Setoran hafalan — pencatatan inti modul Tahfiz.
 *
 * Dua pintu penulisan yang sengaja dipisah:
 * - `store`/`update` mencatat setoran (status `submitted`).
 * - `review` menilai dan menetapkan status akhir. Hanya `review` yang boleh
 *   mengubah status, agar selalu jelas siapa penilai dan kapan.
 *
 * Setiap perubahan memicu progress engine, sehingga
 * `tahfiz_participant_progress` tidak pernah usang.
 */
class TahfizMemorizationRecordController extends BaseApiController
{
    protected array $filterable = [
        'participant_id', 'enrollment_id', 'halaqah_id', 'session_id',
        'surah_id', 'type', 'status',
    ];

    protected array $with = ['surah', 'participant', 'halaqah', 'mistakes.mistakeType'];

    public function __construct(protected ProgressService $progress)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = TahfizMemorizationRecord::query()
            ->with($this->with)
            ->withCount('mistakes');

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
                TahfizMemorizationRecordResource::collection($query->get()),
                'Daftar setoran berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizMemorizationRecordResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar setoran berhasil diambil'
        );
    }

    public function show(TahfizMemorizationRecord $memorizationRecord): JsonResponse
    {
        return $this->successResponse(
            new TahfizMemorizationRecordResource($memorizationRecord->load($this->with)),
            'Detail setoran berhasil diambil'
        );
    }

    public function store(TahfizMemorizationRecordRequest $request): JsonResponse
    {
        $record = DB::transaction(function () use ($request): TahfizMemorizationRecord {
            // `mistakes` disinkronkan terpisah — bukan kolom tabel.
            $payload = collect($request->validated())->except('mistakes')->all();
            $payload['status'] = TahfizMemorizationRecord::STATUS_SUBMITTED;
            $payload['recorded_by'] = $request->user()?->id;
            $payload['mistakes_count'] = $this->mistakesCount($request);

            $record = TahfizMemorizationRecord::create($payload);
            $this->syncMistakes($record, $request);

            return $record;
        });

        $this->progress->onMemorizationRecordSaved($record);

        return $this->createdResponse(
            new TahfizMemorizationRecordResource($record->load($this->with)),
            'Setoran berhasil dicatat dan menunggu penilaian.'
        );
    }

    public function update(TahfizMemorizationRecordRequest $request, TahfizMemorizationRecord $memorizationRecord): JsonResponse
    {
        if (! $memorizationRecord->isPendingReview()) {
            throw ValidationException::withMessages([
                'status' => 'Setoran yang sudah dinilai tidak dapat diubah. Gunakan penilaian ulang hanya melalui guru.',
            ]);
        }

        $record = DB::transaction(function () use ($request, $memorizationRecord): TahfizMemorizationRecord {
            // `mistakes` disinkronkan terpisah — bukan kolom tabel.
            $payload = collect($request->validated())->except('mistakes')->all();
            $payload['mistakes_count'] = $this->mistakesCount($request);

            $memorizationRecord->update($payload);
            $this->syncMistakes($memorizationRecord, $request);

            return $memorizationRecord->fresh();
        });

        $this->progress->onMemorizationRecordSaved($record);

        return $this->successResponse(
            new TahfizMemorizationRecordResource($record->load($this->with)),
            'Setoran berhasil diperbarui.'
        );
    }

    public function destroy(TahfizMemorizationRecord $memorizationRecord): JsonResponse
    {
        $memorizationRecord->delete();
        $this->progress->onMemorizationRecordDeleted($memorizationRecord);

        return $this->successResponse(null, 'Setoran berhasil dihapus.');
    }

    /**
     * Menilai setoran dan menetapkan status akhir.
     */
    public function review(TahfizMemorizationReviewRequest $request, TahfizMemorizationRecord $memorizationRecord): JsonResponse
    {
        $memorizationRecord = DB::transaction(function () use ($request, $memorizationRecord): TahfizMemorizationRecord {
            // Status final tidak boleh dinilai ulang kecuali ditolak/diulang
            // terlebih dahulu — kecuali accepted_with_revision yang memang
            // menunggu perbaikan.
            if (in_array($memorizationRecord->status, [
                TahfizMemorizationRecord::STATUS_ACCEPTED,
                TahfizMemorizationRecord::STATUS_REJECTED,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => "Setoran berstatus {$memorizationRecord->status} tidak dapat dinilai ulang.",
                ]);
            }

            $memorizationRecord->update([
                'status' => $request->input('status'),
                'score' => $request->input('score'),
                'fluency' => $request->input('fluency'),
                'tajwid' => $request->input('tajwid'),
                'makhraj' => $request->input('makhraj'),
                'revision_due_date' => $request->input('revision_due_date'),
                'teacher_note' => $request->input('teacher_note'),
                'reviewed_by' => $request->user()?->id,
                'reviewed_at' => now(),
            ]);

            return $memorizationRecord->fresh();
        });

        $this->progress->onMemorizationRecordSaved($memorizationRecord);

        return $this->successResponse(
            new TahfizMemorizationRecordResource($memorizationRecord->load($this->with)),
            'Setoran berhasil dinilai.'
        );
    }

    /**
     * Ganti kesalahan tercatat dengan yang baru — hapus lalu buat ulang,
     * sama seperti sinkronisasi item rubrik, agar tidak ada yang yatim.
     */
    protected function syncMistakes(TahfizMemorizationRecord $record, TahfizMemorizationRecordRequest $request): void
    {
        $record->mistakes()->delete();

        $payload = $request->mistakesPayload();
        if ($payload === []) {
            return;
        }

        $rows = array_map(fn (array $m) => array_merge($m, ['memorization_record_id' => $record->id]), $payload);
        TahfizMemorizationMistake::insert($rows);
    }

    protected function mistakesCount(TahfizMemorizationRecordRequest $request): int
    {
        return collect($request->mistakesPayload())->sum(fn (array $m) => (int) ($m['count'] ?? 1));
    }
}
