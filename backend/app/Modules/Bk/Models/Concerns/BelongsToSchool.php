<?php

namespace App\Modules\Bk\Models\Concerns;

use App\Modules\Bk\Application\Support\BkTenantResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Isolasi unit sekolah untuk model BK.
 *
 * Bila unit aktif tidak diketahui (instalasi satu sekolah), trait tidak
 * menyaring apa pun: perilakunya identik dengan sebelum kolom ini ada. Bila unit
 * aktif diketahui, setiap kueri otomatis dibatasi pada unit itu — termasuk kueri
 * yang lupa menambahkan syarat, yang justru merupakan sumber kebocoran data yang
 * paling sering terjadi.
 */
trait BelongsToSchool
{
    public static function bootBelongsToSchool(): void
    {
        static::addGlobalScope('bk_school', function (Builder $builder): void {
            $schoolId = app(BkTenantResolver::class)->id();

            if ($schoolId !== null) {
                $builder->where($builder->getModel()->getTable().'.school_id', $schoolId);
            }
        });

        // Baris baru mengikuti unit yang sedang aktif, sehingga tidak ada rekaman
        // BK yang lahir tanpa unit saat penyaringan unit menyala.
        static::creating(function ($model): void {
            if ($model->school_id === null) {
                $model->school_id = app(BkTenantResolver::class)->id();
            }
        });
    }

    /** Kueri tanpa penyaringan unit — hanya untuk perintah/analitik lintas unit. */
    public static function withoutSchoolScope(): Builder
    {
        return static::withoutGlobalScope('bk_school');
    }
}
