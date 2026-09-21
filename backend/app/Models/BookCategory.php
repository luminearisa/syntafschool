<?php

namespace App\Models;

use App\Models\Concerns\LibraryMaster;
use App\Models\Concerns\ScopedLibraryMaster;

/**
 * Kategori koleksi — master existing yang dipakai ulang dan diperluas
 * menjadi hierarkis (spesifikasi §4 & §6).
 *
 * Tabel ini memakai kolom lama `is_active` (boolean) sebagai sumber status,
 * bukan kolom `status` baru — supaya tidak ada dua penanda status yang
 * saling bertentangan. Trait LibraryMaster memetakannya ke `active|inactive`.
 */
class BookCategory extends BaseModel
{
    use LibraryMaster, ScopedLibraryMaster;

    /** Kolom lifecycle existing bertipe boolean. */
    protected function masterStatusAttribute(): string
    {
        return 'is_active';
    }

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}