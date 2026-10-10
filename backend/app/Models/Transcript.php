<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transkrip akademik siswa — snapshot kumulatif nilai per mata pelajaran yang
 * dihimpun dari rapor yang telah diterbitkan (`published`).
 */
class Transcript extends BaseModel
{
    protected $casts = [
        'data' => 'array',
        'generated_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
