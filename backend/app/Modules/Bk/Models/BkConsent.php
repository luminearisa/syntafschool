<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Persetujuan orang tua untuk layanan BK (konseling, berbagi informasi,
asesmen, rujukan).
 *
 * Tabel: `bk_consents`
 */
class BkConsent extends BkModel
{
    protected $table = 'bk_consents';

    use SoftDeletes;

    protected $casts = [
        'requested_at' => 'datetime',
        'decided_at' => 'datetime',
        'expires_at' => 'datetime',
        'confidentiality_level' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(BkReferral::class, 'referral_id');
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'guardian_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(BkCaseDocument::class, 'document_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'PENDING');
    }

    /** Persetujuan yang sudah kedaluwarsa tetapi belum diperbarui statusnya. */
    public function scopeExpirable(Builder $query): Builder
    {
        return $query->where('status', 'APPROVED')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());
    }
}
