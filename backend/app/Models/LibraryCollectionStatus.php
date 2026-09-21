<?php

namespace App\Models;

use App\Models\Concerns\LibraryMaster;
use App\Models\Concerns\ScopedLibraryMaster;

/**
 * Status sirkulasi koleksi (Active, Reference Only, Processing, Withdrawn, …).
 *
 * Dibuat sebagai master karena status ini belum tersedia sebagai enum/domain
 * state di sistem (spesifikasi §18).
 */
class LibraryCollectionStatus extends BaseModel
{
    use LibraryMaster, ScopedLibraryMaster;

    protected $casts = [
        'is_available' => 'boolean',
        'sort_order' => 'integer',
    ];
}