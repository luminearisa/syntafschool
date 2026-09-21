<?php

namespace App\Services\Library\Circulation;

use App\Models\Library;
use App\Models\LibraryAcquisitionItem;
use App\Models\LibraryAcquisitionRequest;
use App\Models\LibraryBibliography;
use App\Models\LibraryBibliographyIdentifier;
use App\Services\Library\Catalog\CatalogService;
use App\Services\Library\Catalog\CollectionItemService;
use App\Services\Library\NumberingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pengadaan koleksi: usulan → persetujuan → pesanan → penerimaan.
 *
 * Penerimaan membuat eksemplar (dan bibliographic record bila belum ada)
 * melalui `CollectionItemService`, sehingga alur
 * "Pengadaan → Terima → Eksemplar → Tersedia" berjalan utuh tanpa
 * menduplikasi logika katalog.
 */
class AcquisitionService
{
    public function __construct(
        protected NumberingService $numbering,
        protected CollectionItemService $items,
        protected CatalogService $catalog,
    ) {
    }

    public function query(Request $request): Builder
    {
        $query = LibraryAcquisitionRequest::query()
            ->with(['supplier:id,name', 'items.bibliography:id,title'])
            ->withCount('items');

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (in_array($column, ['library_id', 'status', 'supplier_id'], true)) {
                $query->where($column, $value);
            }
        }

        if ($request->boolean('open')) {
            $query->open();
        }

        if ($from = $request->query('from')) {
            $query->whereDate('requested_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('requested_at', '<=', $to);
        }

        if ($search = $request->query('search')) {
            $query->where(fn (Builder $q) => $q
                ->where('code', 'like', "{$search}%")
                ->orWhere('supplier_name', 'like', "%{$search}%")
                ->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'like', "{$search}%")));
        }

        return $query->orderByDesc('requested_at')->orderByDesc('id');
    }

    /**
     * Buat permintaan pengadaan beserta barisnya.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): LibraryAcquisitionRequest
    {
        /** @var Library $library */
        $library = Library::query()->findOrFail($data['library_id']);

        return DB::transaction(function () use ($data, $library): LibraryAcquisitionRequest {
            $request = LibraryAcquisitionRequest::create([
                'library_id' => $library->id,
                'code' => $data['code'] ?? $this->numbering->next($library, 'purchase'),
                'supplier_id' => $data['supplier_id'] ?? null,
                'supplier_name' => $data['supplier_name'] ?? null,
                'status' => $data['status'] ?? LibraryAcquisitionRequest::STATUS_DRAFT,
                'requested_at' => $data['requested_at'] ?? Carbon::now()->toDateString(),
                'expected_at' => $data['expected_at'] ?? null,
                'requested_by' => $data['requested_by'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncItems($request, $data['items'] ?? []);

            return $request->refresh()->load('items');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LibraryAcquisitionRequest $request, array $data): LibraryAcquisitionRequest
    {
        if (in_array($request->status, [LibraryAcquisitionRequest::STATUS_RECEIVED], true)) {
            throw ValidationException::withMessages([
                'request' => 'Pengadaan yang sudah diterima tidak dapat diubah.',
            ]);
        }

        return DB::transaction(function () use ($request, $data): LibraryAcquisitionRequest {
            $request->fill(array_intersect_key($data, array_flip([
                'supplier_id', 'supplier_name', 'expected_at', 'notes',
            ])));

            if (isset($data['requested_at'])) {
                $request->requested_at = $data['requested_at'];
            }

            $request->save();

            if (array_key_exists('items', $data)) {
                $request->items()->delete();
                $this->syncItems($request, $data['items']);
            }

            return $request->refresh()->load('items');
        });
    }

    /**
     * Ubah status mengikuti peta transisi.
     */
    public function transition(LibraryAcquisitionRequest $request, string $status, ?string $reason = null, ?int $userId = null): LibraryAcquisitionRequest
    {
        if ($status === $request->status) {
            throw ValidationException::withMessages(['status' => "Pengadaan sudah berstatus \"{$status}\"."]);
        }

        if (! in_array($status, $request->allowedTransitions(), true)) {
            throw ValidationException::withMessages([
                'status' => "Status \"{$request->status}\" tidak dapat diubah ke \"{$status}\".",
            ]);
        }

        $from = $request->status;

        $request->update(array_filter([
            'status' => $status,
            'approved_by' => $status === LibraryAcquisitionRequest::STATUS_APPROVED ? $userId : $request->approved_by,
            'approved_at' => $status === LibraryAcquisitionRequest::STATUS_APPROVED ? Carbon::now() : $request->approved_at,
        ], fn ($value) => $value !== null));

        activity('library')
            ->event('ACQUISITION_STATUS_CHANGED')
            ->performedOn($request)
            ->withProperties(['from' => $from, 'to' => $status, 'reason' => $reason] + $this->context())
            ->log("Status pengadaan: {$from} → {$status}");

        return $request->refresh();
    }

    /**
     * Penerimaan: buat bibliographic record (bila perlu) + eksemplar.
     *
     * @param  array<string, mixed>  $data
     * @return array{request:LibraryAcquisitionRequest,created_items:int,item_ids:list<int>}
     */
    public function receive(LibraryAcquisitionRequest $request, array $data = []): array
    {
        if (! in_array($request->status, [
            LibraryAcquisitionRequest::STATUS_APPROVED,
            LibraryAcquisitionRequest::STATUS_ORDERED,
        ], true)) {
            throw ValidationException::withMessages([
                'request' => 'Penerimaan hanya dapat dilakukan untuk pengadaan yang disetujui atau dipesan.',
            ]);
        }

        /** @var Library $library */
        $library = Library::query()->findOrFail($request->library_id);

        /** @var LibraryAcquisitionItem|null $line */
        $line = null;

        $itemIds = [];

        DB::transaction(function () use ($request, $library, $data, &$itemIds, &$line): void {
            foreach ($request->items as $acquisitionItem) {
                $line = $acquisitionItem;

                $quantity = (int) ($acquisitionItem->quantity);
                $alreadyReceived = (int) $acquisitionItem->received_quantity;
                $toCreate = $quantity - $alreadyReceived;

                if ($toCreate <= 0) {
                    continue;
                }

                $bibliography = $this->resolveBibliography($library, $acquisitionItem);

                $created = $this->items->bulkCreate($bibliography, [
                    'quantity' => $toCreate,
                    'acquisition_date' => $data['received_at'] ?? Carbon::now()->toDateString(),
                    'acquisition_price' => (float) $acquisitionItem->unit_price,
                    'notes' => 'Dari pengadaan '.$request->code,
                ]);

                foreach ($created as $item) {
                    $itemIds[] = (int) $item->id;
                }

                $acquisitionItem->update([
                    'bibliography_id' => $bibliography->id,
                    'received_quantity' => $quantity,
                ]);
            }

            $request->update([
                'status' => LibraryAcquisitionRequest::STATUS_RECEIVED,
                'received_at' => Carbon::now(),
            ]);
        });

        activity('library')
            ->event('ACQUISITION_RECEIVED')
            ->performedOn($request)
            ->withProperties([
                'created_items' => count($itemIds),
            ] + $this->context())
            ->log('Pengadaan diterima dan eksemplar dibuat');

        return [
            'request' => $request->refresh()->load('items'),
            'created_items' => count($itemIds),
            'item_ids' => $itemIds,
        ];
    }

    /**
     * Ringkasan pengadaan untuk laporan (§3).
     *
     * @return array<string, mixed>
     */
    public function summary(?int $libraryId = null, ?string $from = null, ?string $to = null): array
    {
        $scope = fn () => LibraryAcquisitionRequest::query()
            ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
            ->when($from !== null, fn (Builder $q) => $q->whereDate('requested_at', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->whereDate('requested_at', '<=', $to));

        $byStatus = (clone $scope())->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $received = (int) \App\Models\LibraryAcquisitionItem::query()
            ->whereHas('request', fn (Builder $q) => $q
                ->when($libraryId !== null, fn (Builder $qq) => $qq->where('library_id', $libraryId))
                ->when($from !== null, fn (Builder $qq) => $qq->whereDate('requested_at', '>=', $from))
                ->when($to !== null, fn (Builder $qq) => $qq->whereDate('requested_at', '<=', $to)))
            ->sum('received_quantity');

        return [
            'total_requests' => $scope()->count(),
            'by_status' => $byStatus->map(fn ($total) => (int) $total)->all(),
            'total_amount' => round((float) $scope()->sum('total_amount'), 2),
            'received_items' => $received,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    protected function syncItems(LibraryAcquisitionRequest $request, array $items): void
    {
        $total = 0.0;

        foreach ($items as $item) {
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $unitPrice = (float) ($item['unit_price'] ?? 0);

            LibraryAcquisitionItem::create([
                'request_id' => $request->id,
                'title' => $item['title'],
                'isbn' => $item['isbn'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'bibliography_id' => $item['bibliography_id'] ?? null,
                'collection_type_id' => $item['collection_type_id'] ?? null,
                'notes' => $item['notes'] ?? null,
            ]);

            $total += $quantity * $unitPrice;
        }

        $request->update(['total_amount' => round($total, 2)]);
    }

    /**
     * Tautkan ke judul yang sudah ada, atau buat record baru.
     *
     * Pembuatan record memakai `CatalogService` (control number, slug, dan
     * normalisasi judul sudah teruji di sana) — tidak ada logika katalog yang
     * diduplikasi di sini.
     */
    protected function resolveBibliography(Library $library, LibraryAcquisitionItem $item): LibraryBibliography
    {
        if ($item->bibliography_id !== null) {
            return LibraryBibliography::query()->findOrFail($item->bibliography_id);
        }

        // Cocokkan lewat ISBN bila ada, agar tidak membuat duplikat.
        if (filled($item->isbn)) {
            $normalized = LibraryBibliographyIdentifier::normalize($item->isbn);

            $existing = LibraryBibliography::query()
                ->where('library_id', $library->id)
                ->whereHas('identifiers', fn (Builder $q) => $q->where('normalized_value', $normalized))
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $payload = [
            'library_id' => $library->id,
            'title' => $item->title,
            'collection_type_id' => $item->collection_type_id,
            'status' => LibraryBibliography::STATUS_DRAFT,
        ];

        if (filled($item->isbn)) {
            $payload['identifiers'] = [[
                'identifier_type' => 'isbn13',
                'value' => $item->isbn,
                'is_primary' => true,
            ]];
        }

        return $this->catalog->create($payload);
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