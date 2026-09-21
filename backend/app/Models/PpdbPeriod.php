<?php

namespace App\Models;

use App\Models\Concerns\LogsPpdbActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Periode PPDB (mis. PPDB 2027/2028) beserta halaman publiknya.
 */
class PpdbPeriod extends BaseModel
{
    use LogsPpdbActivity, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_OPEN, self::STATUS_CLOSED, self::STATUS_ARCHIVED,
    ];

    /** Perpindahan status yang diizinkan. */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SCHEDULED, self::STATUS_OPEN, self::STATUS_ARCHIVED],
        self::STATUS_SCHEDULED => [self::STATUS_OPEN, self::STATUS_ARCHIVED],
        self::STATUS_OPEN => [self::STATUS_CLOSED],
        self::STATUS_CLOSED => [self::STATUS_OPEN, self::STATUS_ARCHIVED],
        self::STATUS_ARCHIVED => [],
    ];

    protected $casts = [
        'faq' => 'array',
        'registration_start' => 'date:Y-m-d',
        'registration_end' => 'date:Y-m-d',
        'selection_announcement_date' => 'date:Y-m-d',
        're_registration_start' => 'date:Y-m-d',
        're_registration_end' => 'date:Y-m-d',
        'appeal_start' => 'date:Y-m-d',
        'appeal_end' => 'date:Y-m-d',
    ];

    /** Jendela masa sanggah sedang berjalan. */
    public function isAppealWindowOpen(?string $date = null): bool
    {
        $date ??= now()->toDateString();

        if ($this->appeal_start === null || $this->appeal_end === null) {
            return false;
        }

        return $this->appeal_start->toDateString() <= $date
            && $this->appeal_end->toDateString() >= $date;
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function waves(): HasMany
    {
        return $this->hasMany(PpdbWave::class, 'period_id')->orderBy('priority');
    }

    public function programs(): HasMany
    {
        return $this->hasMany(PpdbProgram::class, 'period_id')->orderBy('sort_order');
    }

    public function formFields(): HasMany
    {
        return $this->hasMany(PpdbFormField::class, 'period_id')->orderBy('order');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(PpdbQuestion::class, 'period_id')->orderBy('order');
    }

    public function documentRequirements(): HasMany
    {
        return $this->hasMany(PpdbDocumentRequirement::class, 'period_id')->orderBy('order');
    }

    public function fees(): HasMany
    {
        return $this->hasMany(PpdbRegistrationFee::class, 'period_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(PpdbRegistration::class, 'period_id');
    }

    public function selectionStages(): HasMany
    {
        return $this->hasMany(PpdbSelectionStage::class, 'period_id')->orderBy('order');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /** Periode yang boleh dilihat publik (halaman /ppdb). */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_CLOSED]);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** Pendaftaran sedang dibuka menurut tanggal? */
    public function isRegistrationWindowOpen(?string $date = null): bool
    {
        $date ??= now()->toDateString();

        if (! $this->isOpen()) {
            return false;
        }

        if ($this->registration_start !== null && $this->registration_start->toDateString() > $date) {
            return false;
        }

        return $this->registration_end === null || $this->registration_end->toDateString() >= $date;
    }

    public function allowedTransitions(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function activityEventPrefix(): string
    {
        return 'PPDB_PERIOD';
    }
}