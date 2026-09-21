<?php

namespace App\Models;

use App\Models\Concerns\LibraryMaster;
use App\Models\Concerns\ScopedLibraryMaster;

/**
 * Tag / kata kunci koleksi (spesifikasi §21).
 *
 * Reusable dan terpisah — bukan satu string panjang; relasi many-to-many
 * dibuat pada Step 3.
 */
class LibraryTag extends BaseModel
{
    use LibraryMaster, ScopedLibraryMaster;

    protected $casts = ['sort_order' => 'integer'];
}