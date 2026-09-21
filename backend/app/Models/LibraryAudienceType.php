<?php

namespace App\Models;

use App\Models\Concerns\LibraryMaster;
use App\Models\Concerns\ScopedLibraryMaster;

/**
 * Target pembaca / jenjang baca (spesifikasi §15).
 *
 * Tidak di-hardcode; nantinya dipakai filter OPAC.
 */
class LibraryAudienceType extends BaseModel
{
    use LibraryMaster, ScopedLibraryMaster;

    protected $casts = [
        'min_age' => 'integer',
        'max_age' => 'integer',
        'sort_order' => 'integer',
    ];
}