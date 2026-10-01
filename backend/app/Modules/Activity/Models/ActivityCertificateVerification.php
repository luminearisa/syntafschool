<?php

namespace App\Modules\Activity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Activity\Domain\Enums\CertificateVerificationResult;

/**
 * Catatan setiap upaya verifikasi sertifikat (termasuk yang gagal).
 *
 * Tabel: `activity_certificate_verifications`
 */
class ActivityCertificateVerification extends ActivityModel
{
    /**
     * Tabel riwayat append-only: hanya `created_at` yang dibutuhkan (diisi basis
     * data). Tanpa ini Eloquent akan menulis `updated_at` yang memang tidak ada
     * — dan baris riwayat tidak boleh punya arti "diperbarui".
     */
    public $timestamps = false;

    protected $table = 'activity_certificate_verifications';

    protected $casts = [
        'result' => CertificateVerificationResult::class,
        'verified_at' => 'datetime',
    ];
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(ActivityCertificate::class, 'certificate_id');
    }
}
