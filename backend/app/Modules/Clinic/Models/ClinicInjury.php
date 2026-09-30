<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Modules\Clinic\Domain\Enums\InjurySeverity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cedera/kecelakaan: mekanisme, bagian tubuh, tindakan, rujukan.
 *
 * Tabel: `clinic_injuries`
 */
class ClinicInjury extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_injuries';

    use SoftDeletes;

    protected $casts = [
        'incident_at' => 'datetime',
        'referral_required' => 'boolean',
        'parent_notified' => 'boolean',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(ClinicPatient::class, 'patient_id');
    }
    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'visit_id');
    }
    public function emergency(): BelongsTo
    {
        return $this->belongsTo(ClinicEmergency::class, 'emergency_id');
    }
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
    public function severityEnum(): InjurySeverity
    {
        return InjurySeverity::from($this->severity);
    }
}
