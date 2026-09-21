<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Jejak audit modul Library memakai `spatie/laravel-activitylog`.
 *
 * Perubahan model dicatat hanya bila ada atribut yang berubah (`logOnlyDirty`)
 * dan tidak ada perubahan kosong yang ikut tersimpan.
 *
 * Nama aksi bergaya konvensi spesifikasi (LIBRARY_CREATED,
 * LIBRARY_LOCATION_CREATED, …) dibentuk terpusat di `AppServiceProvider`
 * dari `activityEventPrefix()` — paket menyimpan `event` sebagai nama event
 * model mentah, sehingga penamaannya disesuaikan saat aktivitas disimpan.
 */
trait LogsLibraryActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // `logAll()` wajib: tanpa daftar atribut, `logOnlyDirty()` akan
            // menghasilkan perubahan kosong dan tidak ada yang tercatat.
            ->logAll()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('library');
    }

    // Catatan: model yang memakai trait ini sebaiknya menyediakan
    // `activityEventPrefix(): string` (mis. "LIBRARY", "MASTER"). Bila tidak,
    // nama event mentah ("created"/"updated") yang dipakai — lihat
    // AppServiceProvider yang menyusun nama aksi.
}