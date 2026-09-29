<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use App\Modules\LMS\Domain\Enums\CertificateStatus;
use App\Modules\LMS\Models\Concerns\ResolvesIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sertifikat terbit (§23).
 *
 * `certificate_number` dan `verification_token` tidak pernah diubah setelah
 * dibuat; `snapshot` menyimpan nama & judul course saat penerbitan supaya
 * sertifikat lama tetap konsisten meski data berubah.
 */
class LmsCertificate extends BaseModel
{
    use ResolvesIdentity;

    protected $table = 'lms_certificates';

    protected $casts = [
        'status' => CertificateStatus::class,
        'grade_value' => 'decimal:2',
        'completion_percent' => 'decimal:2',
        'snapshot' => 'array',
        'issued_at' => 'datetime',
        'revoked_at' => 'datetime',
        'download_count' => 'integer',
        'last_downloaded_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(LmsCertificateTemplate::class, 'template_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(self::identityModel(), 'user_id');
    }

    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', CertificateStatus::ISSUED->value);
    }

    public function isValid(): bool
    {
        return $this->status?->isValid() === true;
    }

    public function holderName(): string
    {
        return (string) ($this->snapshot['student_name'] ?? '-');
    }

    public function courseName(): string
    {
        return (string) ($this->snapshot['course_name'] ?? $this->course?->name ?? '-');
    }
}
