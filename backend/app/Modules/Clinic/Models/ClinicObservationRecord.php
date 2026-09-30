<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Catatan berkala selama observasi.
 *
 * Tabel: `clinic_observation_records`
 */
class ClinicObservationRecord extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_observation_records';

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    public function observation(): BelongsTo
    {
        return $this->belongsTo(ClinicObservation::class, 'observation_id');
    }
    public function vitalSign(): BelongsTo
    {
        return $this->belongsTo(ClinicVitalSign::class, 'vital_sign_id');
    }
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }}
