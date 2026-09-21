<?php

namespace App\Models;

use App\Models\Concerns\LibraryMaster;
use App\Models\Concerns\ScopedLibraryMaster;

/**
 * Sumber perolehan koleksi (spesifikasi §16).
 *
 * Akan dipakai modul Acquisition pada Step berikutnya.
 */
class LibraryAcquisitionSource extends BaseModel
{
    use LibraryMaster, ScopedLibraryMaster;

    protected $casts = ['sort_order' => 'integer'];
}