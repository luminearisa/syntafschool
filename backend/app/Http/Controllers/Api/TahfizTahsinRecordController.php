<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizTahsinRecordRequest;
use App\Http\Resources\TahfizTahsinRecordResource;
use App\Models\TahfizRubric;
use App\Models\TahfizRubricItem;
use App\Models\TahfizTahsinRecord;
use App\Models\TahfizTahsinScore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Catatan tahsin — penilaian perbaikan bacaan memakai rubrik.
 *
 * Tahsin bukan hafalan: penilaiannya per kriteria. Skor keseluruhan
 * dihitung dari bobot item rubrik (compute, don't trust) — bukan diambil
 * mentah dari input.
 */
class TahfizTahsinRecordController extends BaseApiController
{
    protected array $filterable = ['participant_id', 'enrollment_id', 'halaqah_id', 'rubric_id', 'instructor_id'];

    protected array $with = ['rubric', 'surah', 'participant', 'instructor', 'scores.rubricItem'];

    public function index(Request $request): JsonResponse
    {
        $query = TahfizTahsinRecord::query()->with($this->with);

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
                TahfizTahsinRecordResource::collection($query->get()),
                'Daftar tahsin berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizTahsinRecordResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar tahsin berhasil diambil'
        );
    }

    public function show(TahfizTahsinRecord $tahsinRecord): JsonResponse
    {
        return $this->successResponse(
            new TahfizTahsinRecordResource($tahsinRecord->load($this->with)),
            'Detail tahsin berhasil diambil'
        );
    }

    public function store(TahfizTahsinRecordRequest $request): JsonResponse
    {
        $record = DB::transaction(function () use ($request): TahfizTahsinRecord {
            $payload = collect($request->validated())->except('scores')->all();
            $payload['recorded_by'] = $request->user()?->id;

            $record = TahfizTahsinRecord::create($payload);
            $this->syncScores($record, $request);

            return $record;
        });

        return $this->createdResponse(
            new TahfizTahsinRecordResource($record->load($this->with)),
            'Tahsin berhasil dicatat.'
        );
    }

    public function update(TahfizTahsinRecordRequest $request, TahfizTahsinRecord $tahsinRecord): JsonResponse
    {
        $record = DB::transaction(function () use ($request, $tahsinRecord): TahfizTahsinRecord {
            $payload = collect($request->validated())->except('scores')->all();
            $tahsinRecord->update($payload);
            $this->syncScores($tahsinRecord, $request);

            return $tahsinRecord->fresh();
        });

        return $this->successResponse(
            new TahfizTahsinRecordResource($record->load($this->with)),
            'Tahsin berhasil diperbarui.'
        );
    }

    public function destroy(TahfizTahsinRecord $tahsinRecord): JsonResponse
    {
        $tahsinRecord->delete();

        return $this->successResponse(null, 'Tahsin berhasil dihapus.');
    }

    /**
     * Ganti skor per kriteria — hapus lalu buat ulang di dalam transaksi,
     * sama seperti sinkronisasi item rubrik.
     */
    protected function syncScores(TahfizTahsinRecord $record, TahfizTahsinRecordRequest $request): void
    {
        $record->scores()->delete();

        $rows = $this->scoreRows($request);
        if ($rows === []) {
            return;
        }

        foreach ($rows as $row) {
            $row['tahsin_record_id'] = $record->id;
            TahfizTahsinScore::create($row);
        }

        $overall = $this->computeOverall($record->load('scores.rubricItem')->getRelation('scores'), $this->rubricMaxScore($record));
        if ($overall !== null) {
            $record->forceFill(['overall_score' => $overall])->save();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function scoreRows(TahfizTahsinRecordRequest $request): array
    {
        $items = TahfizRubricItem::query()
            ->whereIn('id', collect((array) $request->input('scores', []))->pluck('rubric_item_id')->filter()->all())
            ->pluck('criterion', 'id');

        return collect((array) $request->input('scores', []))
            ->map(function (array $s) use ($items): array {
                $itemId = $s['rubric_item_id'] ?? null;

                return [
                    'rubric_item_id' => $itemId ?: null,
                    // Kriteria disalin dari rubrik bila tidak disertakan,
                    // agar rekam jejak tetap terbaca walau rubrik diubah nanti.
                    'criterion' => $s['criterion'] ?? ($itemId ? ($items[$itemId] ?? null) : null),
                    'score' => (float) $s['score'],
                    'note' => $s['note'] ?? null,
                ];
            })
            ->all();
    }

    /**
     * Skor maksimum diambil dari rubrik — `tahfiz_rubric_items` tidak punya
     * kolom max_score sendiri.
     */
    protected function rubricMaxScore(TahfizTahsinRecord $record): float
    {
        return $record->rubric_id !== null
            ? (float) (TahfizRubric::query()->whereKey($record->rubric_id)->value('max_score') ?? 100)
            : 100.0;
    }

    /**
     * Rata-rata tertimbang dari skor kriteria, dinormalisasi ke 0–100.
     */
    protected function computeOverall($scores, float $maxScore): ?float
    {
        if ($scores->isEmpty()) {
            return null;
        }

        $weighted = 0.0;
        $weightSum = 0.0;

        foreach ($scores as $score) {
            $weight = $score->rubricItem ? (float) $score->rubricItem->weight : 1.0;
            $normalized = $maxScore > 0 ? ((float) $score->score * 100.0) / $maxScore : 0.0;

            $weighted += $normalized * $weight;
            $weightSum += $weight;
        }

        if ($weightSum <= 0) {
            return null;
        }

        return round($weighted / $weightSum, 2);
    }
}
