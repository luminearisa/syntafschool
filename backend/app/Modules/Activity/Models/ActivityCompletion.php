<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\CompletionStatus;

/**
 * Keputusan penyelesaian aktivitas beserta aturan yang dipakai saat memutuskan.
 *
 * Tabel: `activity_completions`
 */
class ActivityCompletion extends ActivityModel
{
    protected $table = 'activity_completions';

    protected $casts = [
        'status' => CompletionStatus::class,
        'rule_snapshot' => 'array',
        'final_score' => 'decimal:2',
        'attendance_percentage' => 'decimal:2',
        'certificate_eligible' => 'boolean',
        'verified_at' => 'datetime',
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
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'verified_by');
    }
}
