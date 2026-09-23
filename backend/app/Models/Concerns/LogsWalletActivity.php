<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Jejak audit modul Wallet memakai `spatie/laravel-activitylog` (infra existing).
 *
 * Nama aksi disusun di `AppServiceProvider` dari `activityEventPrefix()`
 * (mis. WALLET_CREATED, WALLET_TOPUP_VERIFIED) mengikuti konvensi modul Library
 * dan PPDB. Yang dicatat justru aktivitas admin atas uang orang lain, jadi
 * setiap perubahan atribut ikut terbaca di `activity_log`.
 */
trait LogsWalletActivity
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
            ->useLogName('wallet');
    }
}
