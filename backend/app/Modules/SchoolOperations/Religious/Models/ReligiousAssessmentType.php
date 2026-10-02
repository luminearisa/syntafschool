<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `religious_assessment_types`.
 */
class ReligiousAssessmentType extends Model
{
    protected $table = 'religious_assessment_types';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'max_score' => 'decimal:2',
        'components' => 'array',
        'weight' => 'decimal:2',
        'rubric' => 'array',
        'requires_supervisor' => 'boolean',
        'sort_order' => 'integer',
        'active' => 'boolean',
        'config_data' => 'array',
    ];

    public function religiousActivityAssessments(): HasMany
    {
        return $this->hasMany(ReligiousActivityAssessment::class, 'assessment_type_id');
    }
}
