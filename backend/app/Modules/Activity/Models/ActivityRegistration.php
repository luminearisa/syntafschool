<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Modules\Activity\Domain\Enums\RegistrationStatus;

/**
 * Pendaftaran siswa pada satu program — satu baris per (program, periode, siswa).
 *
 * Tabel: `activity_registrations`
 */
class ActivityRegistration extends ActivityModel
{
    protected $table = 'activity_registrations';

    use SoftDeletes;

    protected $casts = [
        'status' => RegistrationStatus::class,
        'requirement_snapshot' => 'array',
        'validation_result' => 'array',
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
        'waitlist_position' => 'integer',
        'priority' => 'integer',
    ];
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function period(): BelongsTo
    {
        return $this->belongsTo(ActivityPeriod::class, 'period_id');
    }
    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }
    public function decider(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'decided_by');
    }
    public function histories(): HasMany
    {
        return $this->hasMany(ActivityRegistrationHistory::class, 'registration_id')->orderBy('id');
    }
    public function selectionResults(): HasMany
    {
        return $this->hasMany(ActivitySelectionResult::class, 'registration_id');
    }
    public function consents(): HasMany
    {
        return $this->hasMany(ActivityConsent::class, 'registration_id');
    }
    public function waitlistEntry(): HasOne
    {
        return $this->hasOne(ActivityWaitlistEntry::class, 'registration_id');
    }
    public function membership(): HasOne
    {
        return $this->hasOne(ActivityMembership::class, 'registration_id');
    }
    public function charges(): HasMany
    {
        return $this->hasMany(ActivityCharge::class, 'registration_id');
    }
}
