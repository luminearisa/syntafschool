<?php

namespace App\Services\Library\Opac;

use App\Models\Library;
use App\Models\LibraryBibliography;
use App\Models\LibraryItem;
use App\Models\LibrarySetting;
use App\Services\Library\LibrarySettingsService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * OPAC — Online Public Access Catalog.
 *
 * Hanya menampilkan record berstatus publik (Rule 8) dan **tidak pernah**
 * mengekspos data internal: tanpa id basis data, tanpa info peminjam,
 * tanpa harga perolehan, tanpa catatan internal, tanpa riwayat transaksi
 * (§45 & §48).
 *
 * Ketersediaan selalu **dihitung dari eksemplar** (Rule 9), bukan angka
 * statis yang disimpan pada record, dan dihitung agregat agar tidak ada
 * query per baris (§47 & §66).
 */
class OpacService
{
    public function __construct(protected LibrarySettingsService $settings)
    {
    }

    /**
     * Pencarian katalog publik (§43 & §44).
     */
    public function search(Request $request): LengthAwarePaginator
    {
        $query = LibraryBibliography::query()
            ->public()
            ->select([
                'id', 'library_id', 'slug', 'title', 'subtitle', 'edition_statement',
                'edition_number', 'publisher_id', 'publication_place', 'publication_year',
                'language_id', 'collection_type_id', 'category_id', 'material_type_id',
                'classification_id', 'call_number', 'cover_image', 'thumbnail',
                'digital_available', 'physical_available', 'abstract', 'description',
            ])
            ->with([
                'publisher:id,name',
                'language:id,name',
                'collectionType:id,name',
                'category:id,name',
                'materialType:id,name',
                'classification:id,code,name',
                'creatorLinks.creator:id,name',
                'creatorLinks.creatorRole:id,name',
                'subjects:id,name',
                'genres:id,name',
            ])
            ->withCount('items')
            ->withCount(['items as available_items_count' => fn (Builder $q) => $q->available()])
            // Distribusi status untuk tampilan "3 dari 5 tersedia".
            ->withCount([
                'items as on_loan_items_count' => fn (Builder $q) => $q->where('status', 'on_loan'),
                'items as reference_items_count' => fn (Builder $q) => $q->where('status', 'reference_only'),
            ]);

        $this->applyOpacAvailability($query);
        $this->applyFilters($query, $request);

        // Ketersediaan sebagai filter: hitung dari eksemplar, bukan kolom.
        $availability = $request->query('availability');
        if ($availability === 'available') {
            $query->whereHas('items', fn (Builder $q) => $q->available());
        } elseif ($availability === 'unavailable') {
            $query->whereDoesntHave('items', fn (Builder $q) => $q->available());
        }

        $sort = (string) $request->query('sort_by', 'title');
        $direction = $request->query('sort_dir') === 'desc' ? 'desc' : 'asc';
        $query->orderBy(in_array($sort, ['title', 'publication_year', 'created_at'], true) ? $sort : 'title', $direction);

        return $query->paginate(min(50, (int) $request->query('per_page', 12)));
    }

    /**
     * Detail publik berdasarkan slug (atau slug lama yang pernah dipakai).
     */
    public function findBySlug(string $slug): ?LibraryBibliography
    {
        return LibraryBibliography::query()
            ->public()
            ->with([
                'publisher:id,name', 'language:id,name', 'collectionType:id,name',
                'category:id,name', 'materialType:id,name', 'series:id,name',
                'classification:id,code,name', 'creatorLinks.creator:id,name',
                'creatorLinks.creatorRole:id,name', 'subjects:id,name', 'genres:id,name',
                'tags:id,name', 'identifiers', 'items.location:id,code,name,type',
                'items.condition:id,name', 'items.collectionStatus:id,name',
            ])
            ->where(fn (Builder $q) => $q
                ->where('slug', $slug)
                // Tautan lama tetap dapat ditelusuri (§50).
                ->orWhereJsonContains('previous_slugs', $slug))
            ->tap(fn (Builder $q) => $this->applyOpacAvailability($q))
            ->first();
    }

    /**
     * Hormati konfigurasi perpustakaan: perpustakaan yang mematikan OPAC
     * (`opac.enabled = false`) tidak boleh muncul di katalog publik.
     */
    protected function applyOpacAvailability(Builder $query): void
    {
        $disabled = DB::table('library_settings')
            ->where('key', 'opac.enabled')
            ->whereIn('value', ['0', 'false', ''])
            ->pluck('library_id')
            ->filter()
            ->all();

        if ($disabled !== []) {
            $query->where(fn (Builder $q) => $q
                ->whereNull('library_id')
                ->orWhereNotIn('library_id', $disabled));
        }
    }

    /**
     * Ringkasan ketersediaan dari eksemplar (bukan angka tersimpan).
     *
     * @return array<string, mixed>
     */
    public function availability(LibraryBibliography $bibliography): array
    {
        $rows = LibraryItem::query()
            ->where('bibliography_id', $bibliography->id)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $total = (int) $rows->sum();

        // Item withdrawn/inactive tidak pernah dihitung tersedia (Rule 9).
        $available = (int) ($rows[LibraryItem::AVAILABLE_STATUSES[0]] ?? 0);
        $onLoan = (int) ($rows['on_loan'] ?? 0);
        $reference = (int) ($rows['reference_only'] ?? 0);
        $unavailable = max(0, $total - $available);

        $locations = $this->locations($bibliography, $available);

        return [
            'total_copies' => $total,
            'available' => $available,
            'unavailable' => $unavailable,
            'on_loan' => $onLoan,
            'reference_only' => $reference,
            'by_status' => $rows->map(fn ($total) => (int) $total)->all(),
            'is_available' => $available > 0,
            'locations' => $locations,
        ];
    }

    /**
     * Bentuk publik satu record — hanya informasi yang boleh tampil (§48).
     *
     * @return array<string, mixed>
     */
    public function present(LibraryBibliography $bibliography, bool $withAvailability = true): array
    {
        $settings = $bibliography->library_id
            ? Library::query()->find($bibliography->library_id)
            : null;

        $showLocation = $settings !== null
            ? (bool) $this->settings->get($settings, 'opac.show_location', true)
            : true;
        $showCallNumber = $settings !== null
            ? (bool) $this->settings->get($settings, 'opac.show_call_number', true)
            : true;

        $creators = $bibliography->relationLoaded('creatorLinks')
            ? $bibliography->creatorLinks->map(fn ($link) => [
                'name' => $link->display_name ?: $link->creator?->name,
                'role' => $link->creatorRole?->name,
                'is_primary' => (bool) $link->is_primary,
            ])->values()->all()
            : [];

        return [
            // Identifier publik = slug, bukan id basis data (§49).
            'slug' => $bibliography->slug,
            'title' => $bibliography->title,
            'subtitle' => $bibliography->subtitle,
            'uniform_title' => $bibliography->uniform_title,
            'edition' => $bibliography->edition_statement ?? $bibliography->edition_number,
            'publisher' => $bibliography->publisher?->name,
            'publication_place' => $bibliography->publication_place,
            'publication_year' => $bibliography->publication_year,
            'language' => $bibliography->language?->name,
            'collection_type' => $bibliography->collectionType?->name,
            'category' => $bibliography->category?->name,
            'material_type' => $bibliography->materialType?->name,
            'series' => $bibliography->series?->name,
            'classification' => $bibliography->classification?->code,
            'call_number' => $showCallNumber ? $bibliography->call_number : null,
            'creators' => $creators,
            'subjects' => $bibliography->relationLoaded('subjects')
                ? $bibliography->subjects->pluck('name')->all() : [],
            'genres' => $bibliography->relationLoaded('genres')
                ? $bibliography->genres->pluck('name')->all() : [],
            'tags' => $bibliography->relationLoaded('tags')
                ? $bibliography->tags->pluck('name')->all() : [],
            'identifiers' => $bibliography->relationLoaded('identifiers')
                ? $bibliography->identifiers->map(fn ($i) => [
                    'type' => $i->identifier_type,
                    'value' => $i->value,
                ])->values()->all() : [],
            'abstract' => $bibliography->abstract,
            'description' => $bibliography->description,
            'table_of_contents' => $bibliography->table_of_contents,
            'cover_image' => $bibliography->cover_image,
            'thumbnail' => $bibliography->thumbnail,
            'digital_available' => (bool) $bibliography->digital_available,
            'physical_available' => (bool) $bibliography->physical_available,
            'availability' => $withAvailability ? $this->availability($bibliography) : null,
            'locations' => $showLocation && $withAvailability
                ? $this->locations($bibliography)
                : [],
        ];
    }

    /**
     * Daftar lokasi eksemplar yang tersedia (nama saja, tanpa id internal).
     *
     * @return list<array<string, mixed>>
     */
    protected function locations(LibraryBibliography $bibliography, ?int $onlyAvailable = null): array
    {
        $query = LibraryItem::query()
            ->where('library_items.bibliography_id', $bibliography->id)
            ->join('library_locations', 'library_locations.id', '=', 'library_items.location_id')
            ->select('library_locations.name', 'library_locations.type', DB::raw('count(*) as total'))
            ->groupBy('library_locations.name', 'library_locations.type');

        if ($onlyAvailable !== null && $onlyAvailable > 0) {
            $query->whereIn('library_items.status', LibraryItem::AVAILABLE_STATUSES);
        }

        return $query->get()->map(fn ($row) => [
            'name' => $row->name,
            'type' => $row->type,
            'copies' => (int) $row->total,
        ])->values()->all();
    }

    /**
     * Filter katalog publik (§44) — hanya kolom yang aman.
     */
    protected function applyFilters(Builder $query, Request $request): void
    {
        $filters = (array) $request->query('filter', []);

        foreach ($filters as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (in_array($column, [
                'library_id', 'collection_type_id', 'category_id', 'material_type_id',
                'language_id', 'publisher_id', 'genre_id', 'audience_type_id', 'publication_year',
            ], true)) {
                if ($column === 'genre_id') {
                    $query->whereHas('genres', fn (Builder $g) => $g->whereKey($value));

                    continue;
                }

                $query->where($column, $value);
            }
        }

        // Subject & lokasi lewat relasi.
        if ($subjectId = $request->query('subject_id')) {
            $query->whereHas('subjects', fn (Builder $s) => $s->whereKey($subjectId));
        }

        if ($locationId = $request->query('location_id')) {
            $query->whereHas('items', fn (Builder $i) => $i->where('location_id', $locationId));
        }

        if ($creatorId = $request->query('creator_id')) {
            $query->whereHas('creatorLinks', fn (Builder $c) => $c->where('creator_id', $creatorId));
        }

        if ($yearFrom = $request->query('year_from')) {
            $query->where('publication_year', '>=', (int) $yearFrom);
        }

        if ($yearTo = $request->query('year_to')) {
            $query->where('publication_year', '<=', (int) $yearTo);
        }

        if ($search = $request->query('search')) {
            $this->applySearch($query, (string) $search);
        }
    }

    protected function applySearch(Builder $query, string $search): void
    {
        $term = trim($search);
        $normalized = \App\Services\Library\Catalog\CatalogService::normalizeTitle($term);
        $compact = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $term) ?? '');

        $query->where(fn (Builder $q) => $q
            // Judul dicari sebagai bagian kata (partial match, §43); kolom
            // lain memakai pencocokan berawalan yang ramah indeks.
            ->where('title', 'like', "%{$term}%")
            ->orWhere('normalized_title', 'like', "%{$normalized}%")
            ->orWhereHas('creatorLinks.creator', fn (Builder $c) => $c->where('name', 'like', "{$term}%"))
            ->orWhereHas('publisher', fn (Builder $p) => $p->where('name', 'like', "{$term}%"))
            ->orWhereHas('subjects', fn (Builder $s) => $s->where('name', 'like', "{$term}%"))
            ->orWhereHas('identifiers', fn (Builder $i) => $i->where('normalized_value', $compact))
            ->orWhereHas('classification', fn (Builder $c) => $c->where('code', 'like', "{$term}%")));
    }
}