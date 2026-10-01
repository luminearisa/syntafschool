<?php

namespace App\Modules\Bk\Models;

use App\Models\Student;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Rujukan ke pihak luar seperti psikolog, dokter, atau layanan sosial.
 *
 * Yang disimpan di sini adalah ringkasan hasil yang boleh dibagikan, bukan rekam
 * medis: riwayat klinis tetap milik Modul Clinic/Health.
 *
 * Tabel: `bk_external_referrals`
 */
class BkExternalReferral extends BkModel
{
    protected $table = 'bk_external_referrals';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'consent_obtained_at' => 'datetime',
        'appointment_at' => 'datetime',
        'result_received_at' => 'datetime',
        'referred_at' => 'datetime',
        'closed_at' => 'datetime',
        'referral_date' => 'date',
        'outcome_shared_with_parent' => 'boolean',
        'status' => \App\Modules\Bk\Domain\Enums\ExternalReferralStatus::class,
        'urgency' => \App\Modules\Bk\Domain\Enums\Priority::class,
        'confidentiality_level' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(BkReferralProvider::class, 'referral_provider_id');
    }

    public function consent(): BelongsTo
    {
        return $this->belongsTo(BkConsent::class, 'consent_id');
    }

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(BkFollowUp::class, 'follow_up_id');
    }

    public function referralDocument(): BelongsTo
    {
        return $this->belongsTo(BkCaseDocument::class, 'referral_document_id');
    }

    public function resultDocument(): BelongsTo
    {
        return $this->belongsTo(BkCaseDocument::class, 'result_document_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['COMPLETED', 'CANCELLED', 'DECLINED']);
    }
}
