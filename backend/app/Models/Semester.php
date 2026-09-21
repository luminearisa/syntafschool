<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Semester extends BaseModel
{
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function academicEvents(): HasMany
    {
        return $this->hasMany(AcademicEvent::class);
    }

    public function effectiveDays(): HasMany
    {
        return $this->hasMany(EffectiveDay::class);
    }
}
