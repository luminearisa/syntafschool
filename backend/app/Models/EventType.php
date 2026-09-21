<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Jenis event kalender akademik (mis. Libur Nasional, Ujian Akhir Semester).
 */
class EventType extends BaseModel
{
    use SoftDeletes;

    protected $casts = [
        'is_active' => 'boolean',
        'is_holiday' => 'boolean',
    ];

    public function academicEvents(): HasMany
    {
        return $this->hasMany(AcademicEvent::class);
    }
}
