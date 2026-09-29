<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Organisasi (struktur) di dalam manifest SCORM. */
class LmsScormOrganization extends BaseModel
{
    protected $table = 'lms_scorm_organizations';

    protected $casts = ['is_default' => 'boolean'];

    public function package(): BelongsTo
    {
        return $this->belongsTo(LmsScormPackage::class, 'scorm_package_id');
    }

    public function scoes(): HasMany
    {
        return $this->hasMany(LmsScormSco::class, 'organization_id');
    }
}
