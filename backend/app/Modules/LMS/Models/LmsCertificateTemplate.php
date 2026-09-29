<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Models\Concerns\LogsLmsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Template sertifikat (§22) dengan placeholder {{student_name}} dll.
 */
class LmsCertificateTemplate extends BaseModel
{
    use LogsLmsActivity, SoftDeletes;

    protected $table = 'lms_certificate_templates';

    protected $casts = [
        'template_data' => 'array',
        'is_default' => 'boolean',
    ];

    public function certificates(): HasMany
    {
        return $this->hasMany(LmsCertificate::class, 'template_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'ACTIVE');
    }

    public function activityEventPrefix(): string
    {
        return 'LMS_CERTIFICATE_TEMPLATE';
    }
}
