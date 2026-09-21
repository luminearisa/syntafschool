<?php

namespace App\Models;

use App\Models\Concerns\LibraryMaster;
use App\Models\Concerns\ScopedLibraryMaster;

/**
 * Genre koleksi (Fiction, Novel, Biography, …), terpisah dari kategori
 * (spesifikasi §14). Dapat bertingkat bila diperlukan.
 */
class LibraryGenre extends BaseModel
{
    use LibraryMaster, ScopedLibraryMaster;

    protected $casts = ['sort_order' => 'integer'];
}