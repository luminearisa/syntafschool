<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak pemeriksaan sertifikat publik (§24) — tanpa data pribadi berlebih.
 */
class LmsCertificateVerification extends BaseModel
{
    public const RESULT_VALID = 'VALID';
    public const RESULT_REVOKED = 'REVOKED';
    public const RESULT_NOT_FOUND = 'NOT_FOUND';

    protected $table = 'lms_certificate_verifications';

    protected $casts = [
        'checked_at' => 'datetime',
    ];

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(LmsCertificate::class, 'certificate_id');
    }
}
