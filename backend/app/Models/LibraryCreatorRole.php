<?php

namespace App\Models;

use App\Models\Concerns\LibraryMaster;

/**
 * Peran kreator (Author, Editor, Translator, Illustrator, …).
 *
 * Configurable — bukan enum di kode (spesifikasi §11). Master global; peran
 * yang sama berlaku di semua perpustakaan.
 */
class LibraryCreatorRole extends BaseModel
{
    use LibraryMaster;

    protected $casts = ['sort_order' => 'integer'];
}