<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rawat sementara di ruang observasi UKS.
 *
 * Tabel: `clinic_observations`
 */
class ClinicObservation extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_observations';

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'visit_id');
    }
    public function patient(): BelongsTo
    {
        return $this->belongsTo(ClinicPatient::class, 'patient_id');
    }
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(ClinicLocation::class, 'clinic_id');
    }
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }
    public function records(): HasMany
    {
        return $this->hasMany(ClinicObservationRecord::class, 'observation_id');
    }
    public function isOpen(): bool
    {
        return $this->status === 'ACTIVE';
    }

    public function durationMinutes(): ?int
    {
        if ($this->ended_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInMinutes($this->ended_at);
    }
}
