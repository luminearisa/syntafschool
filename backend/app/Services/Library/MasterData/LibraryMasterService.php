<?php

namespace App\Services\Library\MasterData;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Operasi bersama seluruh master data Library (Step 2).
 *
 * Controller/request hanya menangani bentuk data; service ini memegang
 * aturan relasional yang butuh graf basis data: hierarki (tanpa siklus),
 * scope global vs per-perpustakaan, proteksi hapus berdasarkan pemakaian,
 * dan transisi status (aktif/nonaktif) beserta jejak auditnya.
 */
class LibraryMasterService
{
    /**
     * @return array<string, mixed>
     */
    public function descriptor(string $key): array
    {
        $master = LibraryMasterRegistry::find($key);

        if ($master === null) {
            throw ValidationException::withMessages([
                'master' => "Master data \"{$key}\" tidak dikenal.",
            ]);
        }

        return $master;
    }

    /**
     * Query daftar dengan filter, pencarian, dan pengurutan.
     */
    public function query(array $master, Request $request): Builder
    {
        /** @var Model $model */
        $model = new $master['model'];

        $query = $model->newQuery();

        if ($master['hierarchical']) {
            $query->withCount('children');
        }

        $query->with(['parent'] + $this->relationNames($master));

        if ($master['scoped']) {
            $query->with('library');
        }

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            // Status memakai scope agar tetap benar untuk kolom boolean
            // (book_categories.is_active) maupun enum `status`.
            if ($column === 'status') {
                if ($value === 'active') {
                    $query->active();
                } elseif ($value === 'inactive') {
                    $query->inactive();
                }

                continue;
            }

            if ($column === 'library_id' && $master['scoped']) {
                $query->where('library_id', $value);

                continue;
            }

            $allowed = array_merge($master['filter'], $master['hierarchical'] ? ['parent_id'] : []);

            if (in_array($column, $allowed, true)) {
                $query->where($column, $value);
            }
        }

        if ($search = $request->query('search')) {
            $columns = $master['search'];
            $query->where(fn (Builder $q) => collect($columns)
                ->each(fn (string $column, int $i) => $i === 0
                    ? $q->where($column, 'like', "%{$search}%")
                    : $q->orWhere($column, 'like', "%{$search}%")));
        }

        $sortable = array_merge(['name', 'code', 'sort_order', 'created_at'], $master['hierarchical'] ? ['parent_id'] : []);
        $sortBy = $request->query('sort_by', 'sort_order');
        $sortDir = $request->query('sort_dir') === 'desc' ? 'desc' : 'asc';

        if (in_array($sortBy, $sortable, true)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('sort_order')->orderBy('name');
        }

        if ($sortBy !== 'name') {
            $query->orderBy('name');
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $master, array $data): Model
    {
        $data = $this->normalize($master, $data);
        $this->assertHierarchy($master, null, $data);

        /** @var Model $model */
        $model = new $master['model'];
        $model->fill($data);
        $model->save();

        return $model->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(array $master, Model $model, array $data): Model
    {
        $data = $this->normalize($master, $data);
        $this->assertHierarchy($master, $model, $data);

        $before = $model->statusValue();

        $model->fill($data);
        $model->save();

        // Perubahan status lewat update biasa tetap tercatat sebagai
        // MASTER_DEACTIVATED / MASTER_ACTIVATED (§35).
        $after = $model->refresh()->statusValue();

        if ($before !== $after) {
            activity('library')
                ->event($after === 'inactive' ? 'MASTER_DEACTIVATED' : 'MASTER_ACTIVATED')
                ->performedOn($model)
                ->withProperties(['from' => $before, 'to' => $after] + $this->context())
                ->log($after === 'inactive' ? 'Master data dinonaktifkan' : 'Master data diaktifkan');
        }

        return $model;
    }

    /**
     * Aktifkan / nonaktifkan satu master.
     */
    public function setStatus(array $master, Model $model, string $status, ?string $reason = null): Model
    {
        if (! in_array($status, ['active', 'inactive'], true)) {
            throw ValidationException::withMessages(['status' => 'Status harus active atau inactive.']);
        }

        if ($model->statusValue() === $status) {
            return $model;
        }

        $model->applyMasterStatus($status);
        $model->save();

        activity('library')
            ->event($status === 'inactive' ? 'MASTER_DEACTIVATED' : 'MASTER_ACTIVATED')
            ->performedOn($model)
            ->withProperties([
                'status' => $status,
                'reason' => $reason,
            ] + $this->context())
            ->log($status === 'inactive' ? 'Master data dinonaktifkan' : 'Master data diaktifkan');

        return $model->refresh();
    }

    /**
     * Aktifkan / nonaktifkan banyak master sekaligus (§27).
     *
     * @param  list<int>  $ids
     */
    public function bulkStatus(array $master, array $ids, string $status, ?string $reason = null): int
    {
        if ($ids === []) {
            throw ValidationException::withMessages(['ids' => 'Pilih minimal satu data.']);
        }

        $models = $master['model']::query()->whereIn('id', $ids)->get();

        foreach ($models as $model) {
            $this->setStatus($master, $model, $status, $reason);
        }

        return $models->count();
    }

    /**
     * Hapus master — ditolak bila sudah dipakai atau masih punya anak (§33).
     */
    public function delete(array $master, Model $model): void
    {
        if ($master['hierarchical'] && $model->children()->exists()) {
            throw ValidationException::withMessages([
                'master' => 'Data tidak dapat dihapus karena masih memiliki sub-data. Hapus atau pindahkan sub-data terlebih dahulu.',
            ]);
        }

        $usage = $this->usageCount($master, $model);

        if ($usage > 0) {
            throw ValidationException::withMessages([
                'master' => "Data tidak dapat dihapus karena sudah dipakai oleh {$usage} {$this->usageLabel($master)}. Nonaktifkan saja sebagai gantinya.",
            ]);
        }

        $model->delete();
    }

    public function usageCount(array $master, Model $model): int
    {
        return $this->usageCounts($master, [(int) $model->id])[(int) $model->id] ?? 0;
    }

    /**
     * Jumlah pemakaian untuk sekumpulan id — satu query per definisi
     * pemakaian (bukan per baris), agar daftar tetap ringan.
     *
     * Tabel pemakaian baru ada pada Step berikutnya; pemeriksaan memakai
     * `Schema::hasTable` sehingga proteksi otomatis berlaku saat tabel itu
     * dibuat tanpa mengubah kode ini.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    public function usageCounts(array $master, array $ids): array
    {
        $counts = array_fill_keys($ids, 0);

        if ($ids === []) {
            return $counts;
        }

        foreach ($master['usage'] ?? [] as $usage) {
            if (! Schema::hasTable($usage['table'])) {
                continue;
            }

            $rows = DB::table($usage['table'])
                ->select($usage['column'], DB::raw('count(*) as total'))
                ->whereIn($usage['column'], $ids)
                ->groupBy($usage['column'])
                ->pluck('total', $usage['column']);

            foreach ($rows as $id => $total) {
                $counts[(int) $id] += (int) $total;
            }
        }

        return $counts;
    }

    /**
     * Semua baris untuk keperluan opsi dropdown (tanpa pagination).
     *
     * @return Collection<int, Model>
     */
    public function options(array $master, Request $request): Collection
    {
        return $this->query($master, $request)
            ->select(['id', 'code', 'name', 'library_id', 'status'])
            ->limit(1000)
            ->get();
    }

    /**
     * Aturan hierarki: induk harus ada, se-scope, aktif, bukan diri sendiri,
     * dan bukan keturunannya (mencegah siklus) — spesifikasi §24.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertHierarchy(array $master, ?Model $model, array $data): void
    {
        if (! $master['hierarchical']) {
            return;
        }

        $parentId = $data['parent_id'] ?? null;

        if ($parentId === null || $parentId === '') {
            return;
        }

        $parent = $master['model']::query()->find((int) $parentId);

        if ($parent === null) {
            throw ValidationException::withMessages(['parent_id' => 'Induk tidak ditemukan.']);
        }

        if ($model !== null && (int) $parent->id === (int) $model->id) {
            throw ValidationException::withMessages(['parent_id' => 'Induk tidak boleh dirinya sendiri.']);
        }

        if ($model !== null && in_array((int) $parent->id, $model->descendantIds(), true)) {
            throw ValidationException::withMessages([
                'parent_id' => 'Induk tidak boleh dipilih dari keturunannya sendiri (akan membentuk siklus).',
            ]);
        }

        $childLibraryId = array_key_exists('library_id', $data)
            ? ($data['library_id'] ?: null)
            : $model?->library_id;

        if ((int) ($parent->library_id ?? 0) !== (int) ($childLibraryId ?? 0)) {
            throw ValidationException::withMessages([
                'parent_id' => 'Induk berada pada scope perpustakaan yang berbeda.',
            ]);
        }

        if (! $parent->isMasterActive()) {
            throw ValidationException::withMessages(['parent_id' => 'Induk sedang tidak aktif.']);
        }
    }

    /**
     * Bersihkan & seragamkan data sebelum disimpan.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalize(array $master, array $data): array
    {
        // Master datar tidak menyimpan induk.
        if (! $master['hierarchical']) {
            unset($data['parent_id']);
        }

        // Status ditangani lewat applyMasterStatus (mendukung kolom boolean).
        $status = $data['status'] ?? null;
        unset($data['status'], $data['is_active']);

        // Master global tidak menyimpan library_id.
        if (! $master['scoped']) {
            unset($data['library_id']);
        }

        if (isset($data['code'])) {
            $data['code'] = strtoupper(trim((string) $data['code']));
        }

        if (isset($data['name'])) {
            $data['name'] = trim((string) $data['name']);
        }

        // Simpan status lewat kolom yang benar (enum `status` atau boolean
        // `is_active` pada tabel existing) — jangan sekaligus, karena kolom
        // yang tidak ada akan membuat insert gagal.
        if ($status !== null) {
            /** @var Model $model */
            $model = new $master['model'];

            if ($model->masterStatusAttributeName() === 'is_active') {
                $data['is_active'] = $status === 'active';
            } else {
                $data['status'] = $status;
            }
        }

        return $data;
    }

    /**
     * Nama relasi yang perlu di-eager load (mis. country, system, publisher).
     *
     * @return list<string>
     */
    protected function relationNames(array $master): array
    {
        return array_keys($master['relations'] ?? []);
    }

    protected function usageLabel(array $master): string
    {
        $labels = array_column($master['usage'] ?? [], 'label');

        return $labels === [] ? 'data lain' : implode(' / ', array_unique($labels));
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