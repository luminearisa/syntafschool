<?php

namespace App\Models;

use App\Models\Concerns\LogsPpdbActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dokumen calon — **ber-versi**.
 *
 * Unggahan ulang tidak menimpa berkas lama: versi baru dibuat dan versi lama
 * tetap tersimpan (jejak audit). `is_active` menandai versi yang berlaku.
 */
class PpdbApplicantDocument extends BaseModel
{
    use LogsPpdbActivity, SoftDeletes;

    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_UPLOADED, self::STATUS_UNDER_REVIEW, self::STATUS_APPROVED, self::STATUS_REJECTED,
    ];

    protected $casts = [
        'version' => 'integer',
        'is_active' => 'boolean',
        'file_size' => 'integer',
        'reviewed_at' => 'datetime',
        'uploaded_at' => 'datetime',
    ];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(PpdbRegistration::class, 'registration_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(PpdbApplicant::class, 'applicant_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(PpdbDocumentRequirement::class, 'requirement_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function activityEventPrefix(): string
    {
        return 'PPDB_DOCUMENT';
    }
}