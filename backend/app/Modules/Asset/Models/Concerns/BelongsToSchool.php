<?php

namespace App\Modules\Asset\Models\Concerns;

use App\Modules\Asset\Application\Support\AssetTenantResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Isolasi unit sekolah untuk model Asset & Sarpras.
 *
 * Bila unit aktif tidak diketahui (instalasi satu sekolah), trait tidak
 * menyaring apa pun. Bila diketahui, setiap kueri otomatis dibatasi pada unit
 * itu — termasuk kueri yang lupa menambahkannya, yang justru sumber kebocoran
 * paling sering. Baris baru mengikuti unit aktif, sehingga tidak ada aset yang
 * lahir tanpa unit saat penyaringan menyala.
 */
trait BelongsToSchool
{
    public static function bootBelongsToSchool(): void
    {
        static::addGlobalScope('asset_school', function (Builder $builder): void {
            $schoolId = app(AssetTenantResolver::class)->id();

            if ($schoolId !== null) {
                $builder->where($builder->getModel()->getTable().'.school_id', $schoolId);
            }
        });

        static::creating(function ($model): void {
            if ($model->school_id === null) {
                $model->school_id = app(AssetTenantResolver::class)->id();
            }
        });
    }

    /** Kueri lintas unit — hanya untuk perintah, analitik, dan pengujian. */
    public static function withoutSchoolScope(): Builder
    {
        return static::withoutGlobalScope('asset_school');
    }
}
