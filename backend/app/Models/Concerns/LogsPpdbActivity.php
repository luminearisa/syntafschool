<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Jejak audit modul PPDB memakai `spatie/laravel-activitylog` (audit existing).
 *
 * Perubahan model dicatat hanya bila ada atribut yang berubah, dengan
 * log_name "ppdb". Nama aksi disusun di `AppServiceProvider` dari
 * `activityEventPrefix()` (mis. ACCOUNT_CREATED, REGISTRATION_UPDATED).
 */
trait LogsPpdbActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // `logAll()` wajib: tanpa daftar atribut, `logOnlyDirty()`
            // menghasilkan perubahan kosong dan tidak ada yang tercatat.
            ->logAll()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('ppdb');
    }
}