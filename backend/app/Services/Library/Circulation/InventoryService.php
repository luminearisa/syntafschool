<?php

namespace App\Services\Library\Circulation;

use App\Models\Library;
use App\Models\LibraryInventoryLine;
use App\Models\LibraryInventorySession;
use App\Models\LibraryItem;
use App\Services\Library\NumberingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock opname.
 *
 * Alur §13: buat sesi → pindai → bandingkan (ditemukan / hilang / tak
 * terduga) → finalkan. Perbandingan dilakukan saat finalisasi dengan satu
 * query agregat (bukan memuat seluruh koleksi baris demi baris).
 */
class InventoryService
{
    public function __construct(protected NumberingService $numbering)
    {
    }

    public function query(Request $request): Builder
    {
        $query = LibraryInventorySession::query()
            ->with(['location:id,name'])
            ->withCount('lines');

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (in_array($column, ['library_id', 'status', 'location_id'], true)) {
                $query->where($column, $value);
            }
        }

        return $query->orderByDesc('started_at')->orderByDesc('id');
    }

    /**
     * Buat sesi stock opname.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Library $library, array $data): LibraryInventorySession
    {
        return LibraryInventorySession::create([
            'library_id' => $library->id,
            'code' => $data['code'] ?? $this->numbering->next($library, 'inventory'),
            'name' => $data['name'],
            'status' => LibraryInventorySession::STATUS_ONGOING,
            'location_id' => $data['location_id'] ?? null,
            'started_at' => $data['started_at'] ?? Carbon::now()->toDateString(),
            'created_by' => $data['created_by'] ?? null,
            'notes' => $data['notes'] ?? null,
        ])->refresh();
    }

    /**
     * Pindai satu kode eksemplar (§13).
     *
     * @param  array<string, mixed>  $data
     */
    public function scan(LibraryInventorySession $session, string $code, array $data = []): LibraryInventoryLine
    {
        if ($session->isFinalized()) {
            throw ValidationException::withMessages(['session' => 'Sesi sudah difinalkan dan tidak dapat dipindai lagi.']);
        }

        $code = trim($code);

        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'Kode kosong.']);
        }

        /** @var LibraryItem|null $item */
        $item = LibraryItem::query()
            ->where(fn (Builder $q) => $q
                ->where('barcode', $code)
                ->orWhere('item_code', $code)
                ->orWhere('accession_number', $code))
            ->first();

        // Di luar cakupan sesi (lokasi berbeda / perpustakaan berbeda) atau
        // tidak dikenal → tercatat sebagai tak terduga.
        $inScope = $item !== null
            && (int) $item->library_id === (int) $session->library_id
            && ($session->location_id === null || (int) $item->location_id === (int) $session->location_id);

        $conditionId = $data['condition_id'] ?? null;

        if ($item === null || ! $inScope) {
            return LibraryInventoryLine::create([
                'session_id' => $session->id,
                'item_id' => null,
                'scanned_code' => $code,
                'result' => LibraryInventoryLine::RESULT_UNEXPECTED,
                'scanned_by' => $data['scanned_by'] ?? null,
                'scanned_at' => Carbon::now(),
                'notes' => $data['notes'] ?? ($item === null ? 'Kode tidak dikenal' : 'Di luar cakupan sesi'),
            ])->refresh();
        }

        // Idempoten: memindai ulang memperbarui baris yang sama.
        $line = LibraryInventoryLine::query()->updateOrCreate(
            ['session_id' => $session->id, 'item_id' => $item->id],
            [
                'scanned_code' => $code,
                'result' => $conditionId !== null && $this->isDamaged($conditionId)
                    ? LibraryInventoryLine::RESULT_DAMAGED
                    : LibraryInventoryLine::RESULT_FOUND,
                'condition_id' => $conditionId,
                'scanned_by' => $data['scanned_by'] ?? null,
                'scanned_at' => Carbon::now(),
                'notes' => $data['notes'] ?? null,
            ]
        );

        return $line->refresh();
    }

    /**
     * Finalisasi: hitung selisih dan simpan ringkasannya.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int>
     */
    public function finalize(LibraryInventorySession $session, array $data = []): array
    {
        if ($session->isFinalized()) {
            throw ValidationException::withMessages(['session' => 'Sesi sudah difinalkan.']);
        }

        return DB::transaction(function () use ($session, $data): array {
            $scope = fn () => LibraryItem::query()
                ->where('library_id', $session->library_id)
                ->when($session->location_id !== null, fn (Builder $q) => $q->where('location_id', $session->location_id));

            $expectedIds = $scope()->pluck('id')->map(fn ($id) => (int) $id)->all();

            $scannedIds = LibraryInventoryLine::query()
                ->where('session_id', $session->id)
                ->whereNotNull('item_id')
                ->whereIn('result', [LibraryInventoryLine::RESULT_FOUND, LibraryInventoryLine::RESULT_DAMAGED])
                ->pluck('item_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $missingIds = array_values(array_diff($expectedIds, $scannedIds));

            foreach ($missingIds as $missingId) {
                LibraryInventoryLine::updateOrCreate(
                    ['session_id' => $session->id, 'item_id' => $missingId],
                    ['result' => LibraryInventoryLine::RESULT_MISSING]
                );
            }

            $unexpected = (int) LibraryInventoryLine::query()
                ->where('session_id', $session->id)
                ->where('result', LibraryInventoryLine::RESULT_UNEXPECTED)
                ->count();

            $damaged = (int) LibraryInventoryLine::query()
                ->where('session_id', $session->id)
                ->where('result', LibraryInventoryLine::RESULT_DAMAGED)
                ->count();

            $found = count($scannedIds);

            // Eksemplar hilang dapat ditandai `lost` sekaligus (opsional).
            if (! empty($data['mark_missing_as_lost']) && $missingIds !== []) {
                LibraryItem::query()->whereIn('id', $missingIds)->update(['status' => 'lost']);
            }

            $session->update([
                'status' => LibraryInventorySession::STATUS_FINALIZED,
                'finalized_at' => Carbon::now(),
                'finalized_by' => $data['finalized_by'] ?? null,
                'expected_count' => count($expectedIds),
                'found_count' => $found,
                'missing_count' => count($missingIds),
                'unexpected_count' => $unexpected,
                'damaged_count' => $damaged,
            ]);

            activity('library')
                ->event('INVENTORY_FINALIZED')
                ->performedOn($session)
                ->withProperties([
                    'expected' => count($expectedIds),
                    'found' => $found,
                    'missing' => count($missingIds),
                    'unexpected' => $unexpected,
                    'damaged' => $damaged,
                ] + $this->context())
                ->log('Stock opname difinalkan');

            return [
                'expected' => count($expectedIds),
                'found' => $found,
                'missing' => count($missingIds),
                'unexpected' => $unexpected,
                'damaged' => $damaged,
            ];
        });
    }

    /**
     * Ringkasan hasil sebuah sesi.
     *
     * @return array<string, mixed>
     */
    public function summary(LibraryInventorySession $session): array
    {
        $lines = fn (string $result) => LibraryInventoryLine::query()
            ->where('session_id', $session->id)
            ->where('result', $result)
            ->with('item:id,item_code,barcode,accession_number,location_id', 'item.location:id,name');

        return [
            'session' => [
                'id' => $session->id,
                'code' => $session->code,
                'name' => $session->name,
                'status' => $session->status,
                'started_at' => $session->started_at?->toDateString(),
                'finalized_at' => $session->finalized_at?->toIso8601String(),
            ],
            'expected' => $session->expected_count,
            'found' => $session->found_count,
            'missing' => $session->missing_count,
            'unexpected' => $session->unexpected_count,
            'damaged' => $session->damaged_count,
            'missing_items' => $lines(LibraryInventoryLine::RESULT_MISSING)->get()->map(fn (LibraryInventoryLine $line) => [
                'item_code' => $line->item?->item_code,
                'barcode' => $line->item?->barcode,
                'location' => $line->item?->location?->name,
            ])->values()->all(),
            'damaged_items' => $lines(LibraryInventoryLine::RESULT_DAMAGED)->get()->map(fn (LibraryInventoryLine $line) => [
                'item_code' => $line->item?->item_code,
                'barcode' => $line->item?->barcode,
                'location' => $line->item?->location?->name,
            ])->values()->all(),
            'unexpected_codes' => $lines(LibraryInventoryLine::RESULT_UNEXPECTED)->get()->map(fn (LibraryInventoryLine $line) => [
                'scanned_code' => $line->scanned_code,
                'notes' => $line->notes,
            ])->values()->all(),
        ];
    }

    /**
     * Laporan hasil stock opname pada rentang tanggal (§3).
     *
     * @return array<string, mixed>
     */
    public function report(?int $libraryId = null, ?string $from = null, ?string $to = null): array
    {
        $sessions = LibraryInventorySession::query()
            ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
            ->when($from !== null, fn (Builder $q) => $q->whereDate('started_at', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->whereDate('started_at', '<=', $to))
            ->where('status', LibraryInventorySession::STATUS_FINALIZED)
            ->orderByDesc('started_at')
            ->get();

        return [
            'sessions' => $sessions->map(fn (LibraryInventorySession $session) => [
                'id' => $session->id,
                'code' => $session->code,
                'name' => $session->name,
                'started_at' => $session->started_at?->toDateString(),
                'finalized_at' => $session->finalized_at?->toIso8601String(),
                'expected' => $session->expected_count,
                'found' => $session->found_count,
                'missing' => $session->missing_count,
                'unexpected' => $session->unexpected_count,
                'damaged' => $session->damaged_count,
            ])->values()->all(),
            'totals' => [
                'sessions' => $sessions->count(),
                'expected' => (int) $sessions->sum('expected_count'),
                'found' => (int) $sessions->sum('found_count'),
                'missing' => (int) $sessions->sum('missing_count'),
                'unexpected' => (int) $sessions->sum('unexpected_count'),
                'damaged' => (int) $sessions->sum('damaged_count'),
            ],
        ];
    }

    protected function isDamaged(int $conditionId): bool
    {
        $condition = \App\Models\LibraryCollectionCondition::query()->find($conditionId);

        if ($condition === null) {
            return false;
        }

        return in_array($condition->code, ['POOR', 'DAMAGED'], true);
    }

    /**
     * @return array<string, string>
     */
    protected function context(): array
    {
        if (app()->runningInConsole()) {
            return [];
        }

        return [
            'ip' => request()->ip(),
            'user_agent' => (string) request()->userAgent(),
        ];
    }
}