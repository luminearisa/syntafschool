<?php

namespace App\Models;

use App\Models\Concerns\LibraryMaster;
use App\Models\Concerns\ScopedLibraryMaster;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penerbit — master existing yang dipakai ulang modul Library
 * (spesifikasi §4: jangan membuat `library_publishers`).
 *
 * Dilengkapi kolom katalog pada migrasi Step 2. Data buku/ISBN tidak
 * disimpan di sini; publisher adalah entity tersendiri (§9).
 */
class Publisher extends BaseModel
{
    use LibraryMaster, ScopedLibraryMaster;

    protected $casts = ['sort_order' => 'integer'];

    public function country(): BelongsTo
    {
        return $this->belongsTo(LibraryCountry::class, 'country_id');
    }
}