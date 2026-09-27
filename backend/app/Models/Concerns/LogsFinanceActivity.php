<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Jejak audit modul Finance/Keuangan memakai `spatie/laravel-activitylog`
 * (infra existing yang sama dengan Boarding, Wallet, Library, PPDB — §70).
 *
 * Nama aksi disusun di `AppServiceProvider` dari `activityEventPrefix()`
 * (mis. FINANCE_BILLING_CREATED, FINANCE_PAYMENT_VERIFIED,
 * FINANCE_EXPENSE_APPROVED, FINANCE_PERIOD_CLOSED) sehingga satu konvensi
 * berlaku untuk seluruh tabel keuangan. Transaksi finansial adalah perubahan
 * data paling sensitif, jadi setiap perubahan atribut ikut terbaca di
 * `activity_log` bersama who/when/what/before/after.
 */
trait LogsFinanceActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('finance');
    }
}
