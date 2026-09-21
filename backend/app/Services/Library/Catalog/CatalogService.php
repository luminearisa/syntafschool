<?php

namespace App\Services\Library\Catalog;

use App\Models\Library;
use App\Models\LibraryBibliography;
use App\Models\LibraryBibliographyCreator;
use App\Models\LibraryBibliographyIdentifier;
use App\Models\LibraryItem;
use App\Services\Library\LibrarySettingsService;
use App\Services\Library\NumberingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Katalog bibliografi.
 *
 * Control number dibangkitkan dari konfigurasi penomoran perpustakaan (tidak
 * di-hardcode) dan bersifat **immutable** setelah record dibuat. Slug publik
 * dikelola server agar URL aman, dan slug lama disimpan untuk penelusuran.
 */
class CatalogService
{
    public function __construct(
        protected NumberingService $numbering,
        protected LibrarySettingsService $settings,
    ) {
    }

    /**
     * Daftar katalog (admin) dengan filter, pencarian, dan agregat eksemplar.
     */
    public function query(Request $request): Builder
    {
        $query = LibraryBibliography::query()
            ->with([
                'publisher:id,name,code',
                'language:id,name,code',
                'collectionType:id,name,code',
                'category:id,name,code',
                'materialType:id,name,code',
                'series:id,name,code',
                'classification:id,code,name',
                'creatorLinks.creator:id,name',
                'creatorLinks.creatorRole:id,name',
                'subjects:id,name,code',
                'genres:id,name,code',
                'tags:id,name,code',
                'identifiers',
            ])
            ->withCount('items')
            // Agregat, bukan query per baris: jumlah item tersedia (§66).
            ->withCount(['items as available_items_count' => fn (Builder $q) => $q->available()]);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $allowed = [
                'status', 'library_id', 'publisher_id', 'language_id', 'collection_type_id',
                'category_id', 'material_type_id', 'series_id', 'classification_id',
                'publication_year', 'digital_available', 'physical_available',
            ];

            if (in_array($column, $allowed, true)) {
                $query->where($column, $value);
            }
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

        $sortable = ['title', 'control_number', 'publication_year', 'created_at', 'items_count'];
        $sortBy = (string) $request->query('sort_by', 'created_at');
        $sortDir = $request->query('sort_dir') === 'asc' ? 'asc' : 'desc';

        $query->orderBy(in_array($sortBy, $sortable, true) ? $sortBy : 'created_at', $sortDir);

        return $query;
    }

    /**
     * Pencarian katalog.
     *
     * Memakai kolom ternormalisasi + relasi (identifier unik, nama kreator
     * berawalan) sehingga tidak mengandalkan LIKE liar ke banyak kolom (§51).
     */
    protected function applySearch(Builder $query, string $search): void
    {
        $term = trim($search);
        $normalized = self::normalizeTitle($term);

        $query->where(function (Builder $q) use ($term, $normalized): void {
            $q->where('title', 'like', "%{$term}%")
                ->orWhere('normalized_title', 'like', "%{$normalized}%")
                ->orWhere('control_number', 'like', "{$term}%")
                ->orWhereHas('identifiers', fn (Builder $i) => $i
                    ->where('normalized_value', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $term) ?? '')))
                ->orWhereHas('creatorLinks.creator', fn (Builder $c) => $c->where('name', 'like', "{$term}%"))
                ->orWhereHas('publisher', fn (Builder $p) => $p->where('name', 'like', "{$term}%"))
                ->orWhereHas('subjects', fn (Builder $s) => $s->where('name', 'like', "{$term}%"));
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): LibraryBibliography
    {
        /** @var Library $library */
        $library = Library::query()->findOrFail($data['library_id']);

        return DB::transaction(function () use ($data, $library): LibraryBibliography {
            $bibliography = new LibraryBibliography();

            $bibliography->fill($this->attributes($data));
            $bibliography->library_id = $library->id;
            $bibliography->control_number = $this->numbering->next($library, 'bibliography');
            $bibliography->normalized_title = self::normalizeTitle((string) $data['title']);
            $bibliography->slug = $this->uniqueSlug((string) $data['title']);
            $bibliography->save();

            $this->syncRelations($bibliography, $data, $library);

            return $bibliography->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LibraryBibliography $bibliography, array $data): LibraryBibliography
    {
        return DB::transaction(function () use ($bibliography, $data): LibraryBibliography {
            $attributes = $this->attributes($data);

            // Control number tidak boleh berubah setelah dipakai (§5).
            unset($attributes['control_number']);

            if (array_key_exists('title', $data)) {
                $attributes['normalized_title'] = self::normalizeTitle((string) $data['title']);
            }

            // Slug hanya diregenerasi bila diminta eksplisit; slug lama tetap
            // tersimpan agar tautan lama dapat ditelusuri (§50).
            if ($bibliography->wasRecentlyCreated === false && ! empty($data['regenerate_slug'])) {
                $previous = $bibliography->previous_slugs ?? [];
                $previous[] = $bibliography->slug;
                $attributes['previous_slugs'] = array_values(array_unique($previous));
                $attributes['slug'] = $this->uniqueSlug(
                    (string) ($data['title'] ?? $bibliography->title),
                    $bibliography->id
                );
            }

            $bibliography->fill($attributes);
            $bibliography->save();

            $this->syncRelations($bibliography, $data, $bibliography->library);

            return $bibliography->refresh();
        });
    }

    /**
     * Hapus record — ditolak bila masih memiliki eksemplar (Rule 12).
     */
    public function delete(LibraryBibliography $bibliography): void
    {
        $itemCount = $bibliography->items()->count();

        if ($itemCount > 0) {
            throw ValidationException::withMessages([
                'bibliography' => "Record tidak dapat dihapus karena masih memiliki {$itemCount} eksemplar. Nonaktifkan record sebagai gantinya.",
            ]);
        }

        $bibliography->delete();
    }

    /**
     * Deteksi kemungkinan duplikat (§18) — memberi peringatan, bukan menolak.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, LibraryBibliography>
     */
    public function duplicateCandidates(array $data, ?int $ignoreId = null): Collection
    {
        $title = self::normalizeTitle((string) ($data['title'] ?? ''));
        $identifierValues = $this->identifierValues($data);

        if ($title === '' && $identifierValues === []) {
            return new Collection();
        }

        $query = LibraryBibliography::query()
            ->select(['id', 'control_number', 'title', 'slug', 'publisher_id', 'publication_year'])
            ->with('publisher:id,name')
            ->where(fn (Builder $q) => $q
                ->when($title !== '', fn (Builder $inner) => $inner
                    ->where('normalized_title', $title)
                    ->orWhere('normalized_title', 'like', substr($title, 0, min(24, strlen($title))).'%'))
                ->when($identifierValues !== [], fn (Builder $inner) => $inner
                    ->orWhereHas('identifiers', fn (Builder $i) => $i->whereIn('normalized_value', $identifierValues))));

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query->limit(5)->get();
    }

    /**
     * Tokoh/atribut langsung bibliographic record (tanpa relasi many-to-many).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function attributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip([
            'title', 'subtitle', 'uniform_title', 'edition_statement', 'edition_number',
            'edition_year', 'publisher_id', 'publication_place', 'publication_year',
            'publication_statement', 'language_id', 'collection_type_id', 'category_id',
            'material_type_id', 'series_id', 'classification_id', 'call_number',
            'description', 'abstract', 'table_of_contents', 'physical_description',
            'notes', 'cover_image', 'thumbnail', 'digital_available', 'physical_available',
            'status',
        ]));

        // Call number dapat disusun otomatis dari pola perpustakaan (§13).
        if (blank($attributes['call_number'] ?? null)) {
            $attributes['call_number'] = $this->buildCallNumber($attributes, $data);
        } else {
            $attributes['call_number'] = mb_strtoupper((string) $attributes['call_number']);
        }

        return $attributes;
    }

    /**
     * Susun call number dari pola yang dapat dikonfigurasi
     * (`catalog.call_number_pattern`), dengan token {CLASS} {AUTHOR} {YEAR} {TITLE}.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $data
     */
    protected function buildCallNumber(array $attributes, array $data): ?string
    {
        $libraryId = $data['library_id'] ?? null;
        $pattern = $libraryId !== null
            ? (string) ($this->settings->get(
                Library::query()->findOrFail($libraryId),
                'catalog.call_number_pattern',
                '{CLASS} {AUTHOR} {YEAR}'
            ) ?? '')
            : '{CLASS} {AUTHOR} {YEAR}';

        $classification = $attributes['classification_id'] ?? null;
        $classCode = $classification !== null
            ? (string) (\App\Models\LibraryClassification::query()->whereKey($classification)->value('code') ?? '')
            : '';

        $authorName = '';
        $firstCreator = $data['creators'][0]['creator_id'] ?? null;

        if ($firstCreator !== null) {
            $authorName = (string) (\App\Models\Author::query()->whereKey($firstCreator)->value('name') ?? '');
        } elseif (filled($data['title'] ?? null)) {
            $authorName = (string) $data['title'];
        }

        $authorToken = mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $authorName) ?: 'X', 0, 3));
        $titleToken = mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', (string) ($data['title'] ?? '')) ?: 'X', 0, 3));
        $year = (string) ($attributes['publication_year'] ?? '');

        $callNumber = trim(str_replace(
            ['{CLASS}', '{AUTHOR}', '{YEAR}', '{TITLE}'],
            [$classCode, $authorToken, $year, $titleToken],
            $pattern,
        ));

        return $callNumber === '' ? null : mb_strtoupper($callNumber);
    }

    /**
     * Sinkronkan relasi many-to-many & anak (dikirim hanya bila ada).
     *
     * @param  array<string, mixed>  $data
     */
    protected function syncRelations(LibraryBibliography $bibliography, array $data, ?Library $library): void
    {
        foreach (['subjects' => 'subjects', 'genres' => 'genres', 'tags' => 'tags'] as $key => $relation) {
            if (array_key_exists($key, $data)) {
                $bibliography->{$relation}()->sync((array) $data[$key]);
            }
        }

        if (array_key_exists('creators', $data)) {
            $bibliography->creatorLinks()->delete();

            foreach (array_values((array) $data['creators']) as $index => $creator) {
                LibraryBibliographyCreator::create([
                    'bibliography_id' => $bibliography->id,
                    'creator_id' => $creator['creator_id'],
                    'creator_role_id' => $creator['creator_role_id'] ?? null,
                    'sequence' => $creator['sequence'] ?? $index + 1,
                    'is_primary' => (bool) ($creator['is_primary'] ?? $index === 0),
                    'display_name' => $creator['display_name'] ?? null,
                ]);
            }
        }

        if (array_key_exists('identifiers', $data)) {
            $bibliography->identifiers()->delete();

            foreach ((array) $data['identifiers'] as $identifier) {
                LibraryBibliographyIdentifier::create([
                    'bibliography_id' => $bibliography->id,
                    'identifier_type' => $identifier['identifier_type'],
                    'value' => $identifier['value'],
                    'normalized_value' => LibraryBibliographyIdentifier::normalize($identifier['value']) ?? '',
                    'is_primary' => (bool) ($identifier['is_primary'] ?? false),
                ]);
            }
        }

        if (array_key_exists('files', $data)) {
            $bibliography->files()->delete();

            foreach ((array) $data['files'] as $file) {
                $bibliography->files()->create([
                    'file_type' => $file['file_type'] ?? 'additional',
                    'file_path' => $file['file_path'],
                    'caption' => $file['caption'] ?? null,
                    'sort_order' => $file['sort_order'] ?? 0,
                ]);
            }
        }

        // Penanda ketersediaan fisik mengikuti jumlah eksemplar bila tidak diisi.
        if (! array_key_exists('physical_available', $data)) {
            $bibliography->update([
                'physical_available' => $bibliography->items()->exists(),
            ]);
        }
    }

    /**
     * Judul ternormalisasi: huruf kecil, tanda baca & spasi berlebih dibuang.
     */
    public static function normalizeTitle(string $title): string
    {
        $normalized = Str::lower(trim($title));
        $normalized = preg_replace('/[^a-z0-9\s]/u', ' ', $normalized) ?? '';

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? '');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    protected function identifierValues(array $data): array
    {
        $values = [];

        foreach ((array) ($data['identifiers'] ?? []) as $identifier) {
            $normalized = LibraryBibliographyIdentifier::normalize($identifier['value'] ?? null);

            if ($normalized !== null) {
                $values[] = $normalized;
            }
        }

        return $values;
    }

    /**
     * Slug unik dari judul — mempertimbangkan judul serupa dengan akhiran angka.
     */
    protected function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'bibliografi';
        $base = Str::limit($base, 180, '');
        $slug = $base;
        $suffix = 2;

        while ($this->slugExists($slug, $ignoreId)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    protected function slugExists(string $slug, ?int $ignoreId): bool
    {
        $query = LibraryBibliography::query()->where('slug', $slug)->withTrashed();

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query->exists();
    }
}