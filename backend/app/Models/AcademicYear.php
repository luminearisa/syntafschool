<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicYear extends BaseModel
{
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function semesters(): HasMany
    {
        return $this->hasMany(Semester::class);
    }

    public function schoolClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    public function academicEvents(): HasMany
    {
        return $this->hasMany(AcademicEvent::class);
    }
}
