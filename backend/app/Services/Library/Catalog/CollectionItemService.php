<?php

namespace App\Services\Library\Catalog;

use App\Models\Library;
use App\Models\LibraryBibliography;
use App\Models\LibraryItem;
use App\Services\Library\LibrarySettingsService;
use App\Services\Library\NumberingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Eksemplar koleksi.
 *
 * Item code & nomor inventaris dibangkitkan dari konfigurasi penomoran
 * perpustakaan (bukan hardcode) dan dipisahkan satu sama lain (§21). Barcode
 * boleh otomatis (mengikuti item code) atau manual, sesuai konfigurasi
 * `catalog.barcode_auto`. RFID hanya disiapkan sebagai data — tidak ada
 * integrasi perangkat pada fase ini (§23).
 */
class CollectionItemService
{
    public function __construct(
        protected NumberingService $numbering,
        protected LibrarySettingsService $settings,
    ) {
    }

    public function query(Request $request): Builder
    {
        $query = LibraryItem::query()->with([
            'bibliography:id,title,slug,control_number',
            'library:id,code,name',
            'location:id,code,name,type',
            'condition:id,code,name',
            'collectionStatus:id,code,name',
            'acquisitionSource:id,code,name',
        ]);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (in_array($column, [
                'library_id', 'bibliography_id', 'location_id', 'condition_id',
                'status_id', 'status', 'rfid_status', 'acquisition_source_id',
            ], true)) {
                $query->where($column, $value);
            }
        }

        if ($search = $request->query('search')) {
            $query->where(fn (Builder $q) => $q
                ->where('item_code', 'like', "{$search}%")
                ->orWhere('accession_number', 'like', "{$search}%")
                ->orWhere('barcode', 'like', "{$search}%")
                ->orWhere('rfid_tag', 'like', "{$search}%")
                ->orWhereHas('bibliography', fn (Builder $b) => $b->where('title', 'like', "{$search}%")));
        }

        $sortBy = (string) $request->query('sort_by', 'created_at');
        $sortable = ['item_code', 'accession_number', 'status', 'created_at'];
        $sortDir = $request->query('sort_dir') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy(in_array($sortBy, $sortable, true) ? $sortBy : 'created_at', $sortDir);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(LibraryBibliography $bibliography, array $data): LibraryItem
    {
        return DB::transaction(fn (): LibraryItem => $this->makeItem($bibliography, $data));
    }

    /**
     * Tambah banyak eksemplar sekaligus (§28).
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, LibraryItem>
     */
    public function bulkCreate(LibraryBibliography $bibliography, array $data): Collection
    {
        $quantity = max(1, min(200, (int) $data['quantity']));

        return DB::transaction(function () use ($bibliography, $data, $quantity): Collection {
            $items = new Collection();

            for ($i = 0; $i < $quantity; $i++) {
                $items->push($this->makeItem($bibliography, $data, $i + 1));
            }

            return $items;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LibraryItem $item, array $data): LibraryItem
    {
        $beforeBarcode = $item->barcode;
        $beforeRfid = $item->rfid_tag;

        $item->fill(array_intersect_key($data, array_flip([
            'location_id', 'condition_id', 'status_id', 'acquisition_date',
            'acquisition_source_id', 'acquisition_price', 'funding_source',
            'notes', 'barcode', 'rfid_tag', 'rfid_status',
        ])));

        // RFID yang diisi naik statusnya menjadi assigned bila belum diatur.
        if ($item->rfid_tag !== null && $item->rfid_tag !== '' && $item->rfid_status === 'unassigned') {
            $item->rfid_status = 'assigned';
        }

        if (array_key_exists('status', $data)) {
            $this->assertStatusAllowed((string) $data['status']);
            $item->status = $data['status'];
        }

        $item->save();

        if ($beforeBarcode !== $item->barcode) {
            $this->logChange($item, 'ITEM_BARCODE_CHANGED', ['from' => $beforeBarcode, 'to' => $item->barcode]);
        }

        if ($beforeRfid !== $item->rfid_tag) {
            $this->logChange($item, 'ITEM_RFID_CHANGED', ['from' => $beforeRfid, 'to' => $item->rfid_tag]);
        }

        return $item->refresh();
    }

    /**
     * Ubah status satu item beserta jejak auditnya.
     */
    public function setStatus(LibraryItem $item, string $status, ?string $reason = null): LibraryItem
    {
        $this->assertStatusAllowed($status);

        if ($item->status === $status) {
            return $item;
        }

        $from = $item->status;
        $item->update(['status' => $status]);

        $this->logChange($item, 'ITEM_STATUS_CHANGED', [
            'from' => $from,
            'to' => $status,
            'reason' => $reason,
        ]);

        return $item->refresh();
    }

    /**
     * Aksi massal: pindah lokasi, ubah kondisi, ubah status (§56).
     *
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $attributes
     */
    public function bulkAssign(array $ids, array $attributes): int
    {
        if ($ids === []) {
            throw ValidationException::withMessages(['ids' => 'Pilih minimal satu eksemplar.']);
        }

        if (array_key_exists('status', $attributes)) {
            $this->assertStatusAllowed((string) $attributes['status']);
        }

        $payload = array_intersect_key($attributes, array_flip([
            'location_id', 'condition_id', 'status_id', 'status',
        ]));

        if ($payload === []) {
            throw ValidationException::withMessages(['ids' => 'Tidak ada perubahan yang dikirim.']);
        }

        $items = LibraryItem::query()->whereIn('id', $ids)->get();

        foreach ($items as $item) {
            if (array_key_exists('status', $payload) && $item->status !== $payload['status']) {
                $from = $item->status;
                $item->fill($payload)->save();
                $this->logChange($item, 'ITEM_STATUS_CHANGED', ['from' => $from, 'to' => $payload['status']]);
                continue;
            }

            $item->fill($payload)->save();
        }

        return $items->count();
    }

    /**
     * Bangkitkan barcode otomatis untuk item yang belum punya (§56).
     *
     * @param  list<int>  $ids
     */
    public function bulkGenerateBarcode(array $ids): int
    {
        $items = LibraryItem::query()->whereIn('id', $ids)->whereNull('barcode')->get();

        foreach ($items as $item) {
            $item->barcode = $this->barcodeFrom($item->item_code);
            $item->save();

            $this->logChange($item, 'ITEM_BARCODE_CHANGED', ['from' => null, 'to' => $item->barcode]);
        }

        return $items->count();
    }

    /**
     * Hapus eksemplar — ditolak bila sedang dalam sirkulasi.
     */
    public function delete(LibraryItem $item): void
    {
        if (in_array($item->status, LibraryItem::CIRCULATION_STATUSES, true)) {
            throw ValidationException::withMessages([
                'item' => 'Eksemplar sedang dalam sirkulasi dan tidak dapat dihapus.',
            ]);
        }

        // Riwayat peminjaman menyusul pada Fase 4 — begitu tabelnya ada,
        // proteksi ini otomatis berlaku.
        if (Schema::hasTable('library_loans') && DB::table('library_loans')->where('item_id', $item->id)->exists()) {
            throw ValidationException::withMessages([
                'item' => 'Eksemplar memiliki riwayat peminjaman dan tidak dapat dihapus. Tandai withdrawn sebagai gantinya.',
            ]);
        }

        $item->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function makeItem(LibraryBibliography $bibliography, array $data, ?int $sequence = null): LibraryItem
    {
        /** @var Library $library */
        $library = $bibliography->library ?? Library::query()->findOrFail($bibliography->library_id);

        $itemCode = $data['item_code'] ?? null;

        // Nomor urut eksemplar ke-N pada pembuatan massal diberi kode sendiri.
        if ($itemCode === null) {
            $itemCode = $this->numbering->next($library, 'item');
        } elseif ($sequence !== null) {
            $itemCode = $this->suffixedCode((string) $itemCode, $sequence);
        }

        $autoBarcode = (bool) $this->settings->get($library, 'catalog.barcode_auto', true);

        $item = new LibraryItem();
        $item->fill(array_intersect_key($data, array_flip([
            'location_id', 'condition_id', 'status_id', 'acquisition_date',
            'acquisition_source_id', 'acquisition_price', 'funding_source', 'notes',
        ])));

        $item->library_id = $library->id;
        $item->bibliography_id = $bibliography->id;
        $item->item_code = $itemCode;
        $item->accession_number = $data['accession_number']
            ?? $this->numbering->next($library, 'accession');
        $item->barcode = $data['barcode'] ?? ($autoBarcode ? $this->barcodeFrom($itemCode) : null);
        $item->rfid_tag = $data['rfid_tag'] ?? null;
        $item->rfid_status = filled($item->rfid_tag) ? 'assigned' : 'unassigned';
        $item->status = $data['status'] ?? 'available';

        $this->assertStatusAllowed((string) $item->status);

        $item->save();

        // Penanda ketersediaan fisik pada record mengikuti eksemplarnya.
        if (! $bibliography->physical_available) {
            $bibliography->update(['physical_available' => true]);
        }

        return $item->refresh();
    }

    protected function barcodeFrom(string $itemCode): string
    {
        // Barcode = item code tanpa pemisah, cukup untuk pemindaian dasar.
        return preg_replace('/[^A-Za-z0-9]/', '', $itemCode) ?? $itemCode;
    }

    protected function suffixedCode(string $code, int $sequence): string
    {
        return $code.'-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }

    protected function assertStatusAllowed(string $status): void
    {
        if (! in_array($status, LibraryItem::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => "Status eksemplar \"{$status}\" tidak dikenal.",
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    protected function logChange(LibraryItem $item, string $event, array $properties): void
    {
        activity('library')
            ->event($event)
            ->performedOn($item)
            ->withProperties($properties + $this->context())
            ->log('Eksemplar diperbarui');
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