<?php

namespace App\Models;

use App\Models\Concerns\LibraryMaster;
use App\Models\Concerns\ScopedLibraryMaster;

/**
 * Kondisi fisik koleksi (spesifikasi §17).
 *
 * Step 2 hanya menyiapkan master; penilaian kondisi per eksemplar dilakukan
 * pada Step inventory/item.
 */
class LibraryCollectionCondition extends BaseModel
{
    use LibraryMaster, ScopedLibraryMaster;

    protected $casts = [
        'is_borrowable' => 'boolean',
        'sort_order' => 'integer',
    ];
}