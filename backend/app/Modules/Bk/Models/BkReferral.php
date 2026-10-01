<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\ReferralStatus;
use App\Modules\Bk\Domain\Enums\Priority;
use App\Modules\Bk\Domain\Enums\ReferralCategory;
use App\Modules\Bk\Domain\Enums\ReferralSource;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Referral — pintu masuk utama layanan BK.

Referral tidak memuat catatan konseling: ia memuat apa yang **diamati pelapor**
(deskripsi, observasi, dukungan yang diminta) dan wajib disaring koordinator
sebelum menjadi kasus.
 *
 * Tabel: `bk_referrals`
 */
class BkReferral extends BkModel
{
    protected $table = 'bk_referrals';

    use SoftDeletes;

    protected $casts = [
        'referred_at' => 'datetime',
        'received_at' => 'datetime',
        'screened_at' => 'datetime',
        'assigned_at' => 'datetime',
        'closed_at' => 'datetime',
        'consent_required' => 'boolean',
        'meta' => 'array',
        'status' => \App\Modules\Bk\Domain\Enums\ReferralStatus::class,
        'priority' => \App\Modules\Bk\Domain\Enums\Priority::class,
        'category' => \App\Modules\Bk\Domain\Enums\ReferralCategory::class,
        'source_type' => \App\Modules\Bk\Domain\Enums\ReferralSource::class,
        'confidentiality_level' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function consent(): BelongsTo
    {
        return $this->belongsTo(BkConsent::class, 'consent_id');
    }

    public function screenings(): HasMany
    {
        return $this->hasMany(BkScreening::class, 'referral_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BkCaseEvent::class, 'referral_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(BkAssessment::class, 'referral_id');
    }

    public function supportRequests(): HasMany
    {
        return $this->hasMany(BkSupportRequest::class, 'referral_id');
    }

    /** Antrean yang masih menuntut tindakan BK (dasar widget & penyaring daftar). */
    public function scopeQueue(Builder $query): Builder
    {
        return $query->whereIn('status', ['SUBMITTED', 'RECEIVED', 'SCREENING']);
    }

    /** Referral dengan prioritas mendesak/tinggi yang belum tuntas. */
    public function scopeUrgent(Builder $query): Builder
    {
        return $query->whereIn('priority', ['URGENT', 'HIGH'])
            ->whereNotIn('status', ['RESOLVED', 'REJECTED', 'CANCELLED']);
    }

    public function scopeForStudent(Builder $query, int $studentId): Builder
    {
        return $query->where('student_id', $studentId);
    }
}
