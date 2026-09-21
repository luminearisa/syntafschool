<?php

namespace App\Models;

use App\Models\Concerns\LibraryMaster;
use App\Models\Concerns\ScopedLibraryMaster;

/**
 * Jenis koleksi (Buku, Majalah, Jurnal, Skripsi, E-book, …).
 *
 * Jenis dapat dibuat admin — tidak ada daftar hardcode (spesifikasi §5).
 */
class LibraryCollectionType extends BaseModel
{
    use LibraryMaster, ScopedLibraryMaster;

    protected $casts = [
        'is_digital' => 'boolean',
        'is_physical' => 'boolean',
        'sort_order' => 'integer',
    ];
}