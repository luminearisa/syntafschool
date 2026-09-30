<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Modules\Clinic\Domain\Enums\DischargeCondition;
use App\Modules\Clinic\Domain\Enums\DischargeStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pemulangan pasien: kondisi, instruksi, kembali ke kelas/asrama, kontrol.
 *
 * Tabel: `clinic_discharges`
 */
class ClinicDischarge extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_discharges';

    protected $casts = [
        'discharge_time' => 'datetime',
        'follow_up_required' => 'boolean',
        'follow_up_date' => 'date',
        'return_to_class' => 'boolean',
        'return_to_dorm' => 'boolean',
        'sent_home' => 'boolean',
        'referred' => 'boolean',
        'parent_notified' => 'boolean',
        'is_current' => 'boolean',
        'version' => 'integer',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'visit_id');
    }
    public function patient(): BelongsTo
    {
        return $this->belongsTo(ClinicPatient::class, 'patient_id');
    }
    public function dischargedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discharged_by');
    }
    public function statusEnum(): DischargeStatus
    {
        return DischargeStatus::from($this->status);
    }

    public function conditionEnum(): DischargeCondition
    {
        return DischargeCondition::from($this->condition);
    }

    /** Pemulangan yang mengembalikan siswa ke kegiatan belajar/asrama. */
    public function returnsToActivity(): bool
    {
        return in_array($this->status, ['RETURN_TO_CLASS', 'RETURN_TO_DORM'], true);
    }
}
