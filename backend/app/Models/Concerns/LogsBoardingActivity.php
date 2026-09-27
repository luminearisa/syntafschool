<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Jejak audit modul Boarding/Pesantren memakai `spatie/laravel-activitylog`
 * (infra existing yang sama dengan modul Wallet, Library, dan PPDB).
 *
 * Nama aksi disusun di `AppServiceProvider` dari `activityEventPrefix()`
 * (mis. BOARDING_ROOM_CREATED, BOARDING_PLACEMENT_UPDATED,
 * BOARDING_PERMISSION_APPROVED) sehingga satu konvensi berlaku untuk seluruh
 * tabel asrama. Yang dicatat justru perubahan data santri orang lain —
 * penempatan, absensi, izin keluar, poin pelanggaran — jadi setiap perubahan
 * atribut ikut terbaca di `activity_log` (§57, §73).
 */
trait LogsBoardingActivity
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
            ->useLogName('boarding');
    }
}
