<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Tahfiz\TahfizMutabaahRequest;
use App\Http\Resources\TahfizMutabaahResource;
use App\Models\TahfizMutabaah;
use App\Models\TahfizMutabaahEntry;
use App\Models\TahfizMutabaahItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mutabaah harian peserta Tahfiz.
 *
 * Item bersifat konfigurabel (tabel `tahfiz_mutabaah_items`), bukan hardcoded.
 * Pengisian pada tanggal yang sama memperbarui baris yang ada — idempoten,
 * sesuai prinsip "satu baris per peserta per tanggal".
 */
class TahfizMutabaahController extends BaseApiController
{
    protected array $filterable = ['participant_id'];

    protected array $with = ['participant', 'entries.item'];

    public function index(Request $request): JsonResponse
    {
        $query = TahfizMutabaah::query()->with($this->with);

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
                TahfizMutabaahResource::collection($query->get()),
                'Daftar mutabaah berhasil diambil'
            );
        }

        return $this->successResponse(
            TahfizMutabaahResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar mutabaah berhasil diambil'
        );
    }

    public function show(TahfizMutabaah $mutabaah): JsonResponse
    {
        return $this->successResponse(
            new TahfizMutabaahResource($mutabaah->load($this->with)),
            'Detail mutabaah berhasil diambil'
        );
    }

    /**
     * Catat atau perbarui mutabaah satu tanggal.
     */
    public function store(TahfizMutabaahRequest $request): JsonResponse
    {
        $mutabaah = DB::transaction(function () use ($request): TahfizMutabaah {
            $participantId = (int) $request->input('participant_id');
            $date = $request->input('date');

            // unique(participant_id, date) tidak bisa diandalkan di SQLite
            // untuk pembaruan, jadi cari dulu secara eksplisit.
            $mutabaah = TahfizMutabaah::query()
                ->where('participant_id', $participantId)
                ->whereDate('date', $date)
                ->first();

            if ($mutabaah === null) {
                $mutabaah = TahfizMutabaah::create([
                    'participant_id' => $participantId,
                    'date' => $date,
                    'notes' => $request->input('notes'),
                ]);
            } else {
                $mutabaah->update(['notes' => $request->input('notes')]);
            }

            $this->syncEntries($mutabaah, $request);

            return $mutabaah;
        });

        return $this->createdResponse(
            new TahfizMutabaahResource($mutabaah->load($this->with)),
            'Mutabaah berhasil disimpan.'
        );
    }

    public function update(TahfizMutabaahRequest $request, TahfizMutabaah $mutabaah): JsonResponse
    {
        DB::transaction(function () use ($request, $mutabaah): void {
            $mutabaah->update($request->only('notes'));
            $this->syncEntries($mutabaah, $request);
        });

        return $this->successResponse(
            new TahfizMutabaahResource($mutabaah->load($this->with)),
            'Mutabaah berhasil diperbarui.'
        );
    }

    public function destroy(TahfizMutabaah $mutabaah): JsonResponse
    {
        $mutabaah->delete();

        return $this->successResponse(null, 'Mutabaah berhasil dihapus.');
    }

    /**
     * Ganti seluruh entri — hapus lalu buat ulang dalam transaksi.
     */
    protected function syncEntries(TahfizMutabaah $mutabaah, TahfizMutabaahRequest $request): void
    {
        $mutabaah->entries()->delete();

        $labels = TahfizMutabaahItem::query()
            ->whereIn('id', collect((array) $request->input('entries', []))->pluck('item_id')->filter()->all())
            ->pluck('label', 'id');

        $rows = collect((array) $request->input('entries', []))
            ->map(function (array $e) use ($labels, $mutabaah): array {
                $itemId = $e['item_id'] ?? null;

                return [
                    'mutabaah_id' => $mutabaah->id,
                    'item_id' => $itemId ?: null,
                    'item_label' => $e['item_label'] ?? ($itemId ? ($labels[$itemId] ?? null) : null),
                    'done' => (bool) ($e['done'] ?? false),
                    'value' => $e['value'] ?? null,
                    'note' => $e['note'] ?? null,
                ];
            })
            ->all();

        if ($rows !== []) {
            TahfizMutabaahEntry::insert($rows);
        }
    }
}
