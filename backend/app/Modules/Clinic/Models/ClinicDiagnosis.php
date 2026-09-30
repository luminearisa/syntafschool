<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Modules\Clinic\Domain\Enums\DiagnosisType;
use Illuminate\Database\Eloquent\Builder;

/**
 * Diagnosis per kunjungan (utama/sekunder/gejala/sementara).
 *
 * Tabel: `clinic_diagnoses`
 */
class ClinicDiagnosis extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_diagnoses';

    protected $casts = [
        'diagnosed_at' => 'datetime',
        'is_current' => 'boolean',
        'version' => 'integer',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'visit_id');
    }
    public function master(): BelongsTo
    {
        return $this->belongsTo(ClinicDiagnosisMaster::class, 'master_id');
    }
    public function diagnosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diagnosed_by');
    }
    public function amendedFrom(): BelongsTo
    {
        return $this->belongsTo(ClinicDiagnosis::class, 'amended_from_id');
    }
    public function typeEnum(): DiagnosisType
    {
        return DiagnosisType::from($this->type);
    }
}
