<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Guardian;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kegawatdaruratan: tindakan segera, kontak orang tua, rujukan, transportasi.
 *
 * Tabel: `clinic_emergencies`
 */
class ClinicEmergency extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_emergencies';

    use SoftDeletes;

    protected $casts = [
        'incident_at' => 'datetime',
        'parent_contacted' => 'boolean',
        'parent_contacted_at' => 'datetime',
        'referral_required' => 'boolean',
        'ambulance_required' => 'boolean',
        'closed_at' => 'datetime',
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
    public function contactedGuardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'contacted_guardian_id');
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    public function isOpen(): bool
    {
        return in_array($this->status, ['OPEN', 'STABILIZED', 'REFERRED'], true);
    }
}
