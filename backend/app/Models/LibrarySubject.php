<?php

namespace App\Models;

use App\Models\Concerns\LibraryMaster;
use App\Models\Concerns\ScopedLibraryMaster;

/**
 * Subjek/topik koleksi, dapat bertingkat (spesifikasi §12).
 *
 * Berbeda dari `subjects` (mata pelajaran akademik) — tabel ini khusus
 * topik katalog perpustakaan.
 */
class LibrarySubject extends BaseModel
{
    use LibraryMaster, ScopedLibraryMaster;

    protected $casts = ['sort_order' => 'integer'];
}