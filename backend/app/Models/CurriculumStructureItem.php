<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris struktur kurikulum: mapel + kelompok + semester + alokasi JP.
 */
class CurriculumStructureItem extends BaseModel
{
    protected $casts = [
        'semester' => 'integer',
        'weekly_hours' => 'integer',
        'is_required' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function structure(): BelongsTo
    {
        return $this->belongsTo(CurriculumStructure::class, 'curriculum_structure_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function subjectGroup(): BelongsTo
    {
        return $this->belongsTo(SubjectGroup::class);
    }
}
