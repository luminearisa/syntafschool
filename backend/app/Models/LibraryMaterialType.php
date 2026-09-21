<?php

namespace App\Models;

use App\Models\Concerns\LibraryMaster;

/**
 * Bentuk media koleksi (Printed, Digital, Audio, Video, Multimedia, …).
 *
 * Dipisahkan dari jenis koleksi agar jenis bibliografi tidak tercampur
 * dengan bentuk medianya (spesifikasi §7). Master global.
 */
class LibraryMaterialType extends BaseModel
{
    use LibraryMaster;

    protected $casts = ['sort_order' => 'integer'];
}