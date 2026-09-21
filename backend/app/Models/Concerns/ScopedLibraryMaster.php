<?php

namespace App\Models\Concerns;

use App\Models\Library;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Master yang ber-scope perpustakaan.
 *
 * Aturan scope (spesifikasi §22):
 * `library_id = NULL` → global (berlaku semua perpustakaan)
 * `library_id = X`    → hanya milik perpustakaan X
 *
 * Dataset global hanya boleh diubah oleh pengguna yang berwenang mengelola
 * master tersebut — penegakannya ada di service, bukan hanya di UI.
 */
trait ScopedLibraryMaster
{
    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull('library_id');
    }

    public function scopeForLibrary(Builder $query, int $libraryId): Builder
    {
        // Termasuk master global + master milik perpustakaan tersebut.
        return $query->where(fn (Builder $q) => $q
            ->whereNull('library_id')
            ->orWhere('library_id', $libraryId));
    }

    public function isGlobal(): bool
    {
        return $this->library_id === null;
    }
}