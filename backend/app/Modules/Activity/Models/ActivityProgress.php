<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\ProgressStatus;

/**
 * Ringkasan perkembangan siswa dalam satu keanggotaan (kehadiran, penilaian, keterampilan).
 *
 * Tabel: `activity_progress`
 */
class ActivityProgress extends ActivityModel
{
    protected $table = 'activity_progress';

    protected $casts = [
        'status' => ProgressStatus::class,
        'attendance_percentage' => 'decimal:2',
        'average_score' => 'decimal:2',
        'skills' => 'array',
        'completion_snapshot' => 'array',
        'calculated_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
    public function membership(): BelongsTo
    {
        return $this->belongsTo(ActivityMembership::class, 'membership_id');
    }
    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
}
