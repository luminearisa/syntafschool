<?php

namespace App\Models;

use App\Models\Concerns\LibraryMaster;
use App\Models\Concerns\ScopedLibraryMaster;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kreator (orang) — master existing yang dipakai ulang modul Library
 * (spesifikasi §4 & §10).
 *
 * Tabel tetap bernama `authors` (nama historis), tetapi perannya sebagai
 * *person/creator*: satu orang dapat menjadi Author pada satu karya dan
 * Translator pada karya lain. Peran melekat pada relasi karya↔kreator
 * (`creator_roles`) yang dibangun pada Step 3 — bukan pada tabel ini.
 */
class Author extends BaseModel
{
    use LibraryMaster, ScopedLibraryMaster;

    protected $casts = ['sort_order' => 'integer'];

    public function country(): BelongsTo
    {
        return $this->belongsTo(LibraryCountry::class, 'country_id');
    }
}