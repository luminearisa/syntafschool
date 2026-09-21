<?php

namespace App\Models;

use App\Models\Concerns\LibraryMaster;
use App\Models\Concerns\ScopedLibraryMaster;

/**
 * Sistem klasifikasi (DDC, LCC, Custom, Islamic Classification, …).
 *
 * Sistem tidak di-hardcode DDC: admin dapat menambah sistem sendiri
 * (spesifikasi §13).
 */
class LibraryClassificationSystem extends BaseModel
{
    use LibraryMaster, ScopedLibraryMaster;

    protected $casts = ['sort_order' => 'integer'];
}