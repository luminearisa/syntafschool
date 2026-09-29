<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** SCO — unit yang diluncurkan dan dilacak (§12). */
class LmsScormSco extends BaseModel
{
    protected $table = 'lms_scorm_scoes';

    protected $casts = ['parameters' => 'array'];

    public function package(): BelongsTo
    {
        return $this->belongsTo(LmsScormPackage::class, 'scorm_package_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(LmsScormOrganization::class, 'organization_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(LmsScormAttempt::class, 'sco_id');
    }
}
