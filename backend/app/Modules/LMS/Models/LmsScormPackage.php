<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use App\Modules\LMS\Domain\Enums\ScormPackageStatus;
use App\Modules\LMS\Domain\Enums\ScormVersion;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Paket SCORM yang diunggah (§12). */
class LmsScormPackage extends BaseModel
{
    protected $table = 'lms_scorm_packages';

    protected $casts = [
        'version' => ScormVersion::class,
        'status' => ScormPackageStatus::class,
        'manifest' => 'array',
        'validation' => 'array',
        'organizations_count' => 'integer',
        'sco_count' => 'integer',
    ];

    public function organizations(): HasMany
    {
        return $this->hasMany(LmsScormOrganization::class, 'scorm_package_id');
    }

    public function scoes(): HasMany
    {
        return $this->hasMany(LmsScormSco::class, 'scorm_package_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(LmsScormAttempt::class, 'scorm_package_id');
    }

    public function isReady(): bool
    {
        return $this->status === ScormPackageStatus::IMPORTED;
    }
}
