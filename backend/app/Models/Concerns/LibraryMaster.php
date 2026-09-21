<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Perilaku bersama seluruh master data Library (Step 2).
 *
 * - **Scope**: `library_id = NULL` berarti global, `X` berarti hanya milik
 *   perpustakaan X (spesifikasi §22).
 * - **Hierarki**: lewat `parent_id` untuk master bertingkat (§24).
 * - **Lifecycle**: `active | inactive`; master yang sudah dipakai tidak
 *   dihapus melainkan dinonaktifkan (§23).
 * - **Audit**: memakai log_name "library" dengan nama aksi `MASTER_*` (§35).
 *
 * Master existing yang tabelnya dipakai ulang dapat mengubah kolom lifecycle
 * lewat `$masterStatusAttribute` (mis. `book_categories.is_active`).
 */
trait LibraryMaster
{
    use LogsLibraryActivity, SoftDeletes;

    /**
     * Nama kolom sumber status. Master existing yang memakai kolom boolean
     * (mis. `book_categories.is_active`) cukup meng-override method ini —
     * bukan properti, karena trait property tidak boleh dideklarasikan ulang
     * dengan nilai berbeda.
     */
    protected function masterStatusAttribute(): string
    {
        return 'status';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $this->applyMasterStatusFilter($query, 'active');
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $this->applyMasterStatusFilter($query, 'inactive');
    }

    /** Nama kolom lifecycle pada tabel master ini. */
    public function masterStatusAttributeName(): string
    {
        return $this->masterStatusAttribute();
    }

    /** Status dalam bentuk seragam: `active` | `inactive`. */
    public function statusValue(): string
    {
        if ($this->masterStatusAttribute() === 'is_active') {
            return $this->is_active ? 'active' : 'inactive';
        }

        return (string) $this->status;
    }

    public function isMasterActive(): bool
    {
        return $this->statusValue() === 'active';
    }

    /** Set status pada kolom yang benar (mendukung kolom boolean existing). */
    public function applyMasterStatus(string $status): void
    {
        if ($this->masterStatusAttribute() === 'is_active') {
            $this->is_active = $status === 'active';

            return;
        }

        $this->status = $status;
    }

    /**
     * ID seluruh keturunan (rekursif) — dipakai mencegah siklus saat pindah.
     *
     * @return list<int>
     */
    public function descendantIds(): array
    {
        $ids = [];
        $stack = $this->children()->pluck('id')->all();

        while ($stack !== []) {
            $id = array_pop($stack);
            $ids[] = (int) $id;
            $stack = array_merge(
                $stack,
                static::query()->where('parent_id', $id)->pluck('id')->all()
            );
        }

        return $ids;
    }

    public function activityEventPrefix(): string
    {
        return 'MASTER';
    }

    protected function applyMasterStatusFilter(Builder $query, string $status): Builder
    {
        if ($this->masterStatusAttribute() === 'is_active') {
            return $query->where('is_active', $status === 'active');
        }

        return $query->where('status', $status);
    }
}