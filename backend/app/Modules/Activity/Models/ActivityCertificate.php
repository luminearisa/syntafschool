<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Modules\Activity\Domain\Enums\CertificateStatus;
use App\Modules\Activity\Domain\Enums\CertificateType;

/**
 * Sertifikat aktivitas ber-nomor unik yang dapat diverifikasi publik.
 *
 * Tabel: `activity_certificates`
 */
class ActivityCertificate extends ActivityModel
{
    protected $table = 'activity_certificates';

    use SoftDeletes;

    protected $casts = [
        'type' => CertificateType::class,
        'status' => CertificateStatus::class,
        'issued_date' => 'date',
        'revoked_at' => 'datetime',
        'metadata' => 'array',
    ];
    public function student(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }
    public function program(): BelongsTo
    {
        return $this->belongsTo(ActivityProgram::class, 'program_id');
    }
    public function period(): BelongsTo
    {
        return $this->belongsTo(ActivityPeriod::class, 'period_id');
    }
    public function achievement(): BelongsTo
    {
        return $this->belongsTo(ActivityAchievement::class, 'achievement_id');
    }
    public function competition(): BelongsTo
    {
        return $this->belongsTo(ActivityCompetition::class, 'competition_id');
    }
    public function completion(): BelongsTo
    {
        return $this->belongsTo(ActivityCompletion::class, 'completion_id');
    }
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'issued_by');
    }
    public function verifications(): HasMany
    {
        return $this->hasMany(ActivityCertificateVerification::class, 'certificate_id');
    }
}
