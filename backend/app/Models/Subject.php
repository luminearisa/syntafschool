<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends BaseModel
{
    protected $casts = [
        'credit' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class);
    }

    /**
     * Mapel yang menjadi prasyarat untuk mapel ini.
     */
    public function prerequisites(): HasMany
    {
        return $this->hasMany(SubjectPrerequisite::class, 'subject_id');
    }

    /**
     * Mapel lain yang membutuhkan mapel ini sebagai prasyarat.
     */
    public function isPrerequisiteFor(): HasMany
    {
        return $this->hasMany(SubjectPrerequisite::class, 'prerequisite_subject_id');
    }

    /**
     * Item struktur kurikulum yang memuat mapel ini.
     */
    public function structureItems(): HasMany
    {
        return $this->hasMany(CurriculumStructureItem::class);
    }
}
