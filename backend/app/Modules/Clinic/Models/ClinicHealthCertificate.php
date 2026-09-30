<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Modules\Clinic\Domain\Enums\CertificateStatus;
use App\Modules\Clinic\Domain\Enums\CertificateType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Surat keterangan sehat/clearance dengan alur permintaan → peninjauan → penerbitan.
 *
 * Tabel: `clinic_health_certificates`
 */
class ClinicHealthCertificate extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_health_certificates';

    use SoftDeletes;

    protected $casts = [
        'valid_from' => 'date',
        'valid_until' => 'date',
        'issued_at' => 'datetime',
        'version' => 'integer',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(ClinicPatient::class, 'patient_id');
    }
    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'visit_id');
    }
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
    public function statusEnum(): CertificateStatus
    {
        return CertificateStatus::from($this->status);
    }

    public function typeEnum(): CertificateType
    {
        return CertificateType::from($this->type);
    }

    public function isValid(): bool
    {
        if (! $this->statusEnum()->isUsable()) {
            return false;
        }

        return $this->valid_until === null || $this->valid_until->isFuture();
    }
}
