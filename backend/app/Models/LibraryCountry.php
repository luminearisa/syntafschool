<?php

namespace App\Models;

use App\Models\Concerns\LibraryMaster;

/**
 * Master negara (spesifikasi §19). Master global.
 *
 * Dibutuhkan publisher & creator; sistem belum memiliki master negara
 * (wilayah existing hanya sampai provinsi).
 */
class LibraryCountry extends BaseModel
{
    use LibraryMaster;

    protected $casts = ['sort_order' => 'integer'];
}