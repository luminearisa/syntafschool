<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\AssessmentTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Template asesmen yang dapat dikonfigurasi sekolah.
 *
 * Tabel: `bk_assessment_templates`
 */
class BkAssessmentTemplate extends BkModel
{
    protected $table = 'bk_assessment_templates';

    use SoftDeletes;

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'requires_consent' => 'boolean',
        'target' => \App\Modules\Bk\Domain\Enums\AssessmentTarget::class,
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(BkAssessmentSection::class, 'template_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(BkAssessmentQuestion::class, 'template_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(BkAssessment::class, 'template_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
