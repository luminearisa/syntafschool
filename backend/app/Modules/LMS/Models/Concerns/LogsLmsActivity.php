<?php

namespace App\Modules\LMS\Models\Concerns;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Jejak audit modul LMS (§36) memakai paket activitylog yang sama dengan modul
 * lain. Nama aksi bergaya spesifikasi (LMS_COURSE_CREATED, LMS_ENROLLMENT_CREATED,
 * …) disusun terpusat dari `activityEventPrefix()`.
 */
trait LogsLmsActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('lms');
    }
}
