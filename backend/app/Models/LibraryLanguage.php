<?php

namespace App\Models;

use App\Models\Concerns\LibraryMaster;

/**
 * Master bahasa (spesifikasi §8). Master global.
 *
 * Satu bibliographic record dapat memakai lebih dari satu bahasa pada Step 3.
 */
class LibraryLanguage extends BaseModel
{
    use LibraryMaster;

    protected $casts = ['sort_order' => 'integer'];
}