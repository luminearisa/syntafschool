<?php

namespace App\Models;

use App\Models\Concerns\LogsPpdbActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Transaksi pendaftaran: satu calon pada satu periode/gelombang/program.
 *
 * Dua status dipisah agar tidak kacau: `status` (alur pendaftaran) dan
 * `payment_status` (pembayaran), ditambah `document_status` (kelengkapan
 * dokumen).
 */
class PpdbRegistration extends BaseModel
{
    use LogsPpdbActivity, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_NEED_REVISION = 'need_revision';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_SELECTION = 'selection';
    public const STATUS_PASSED = 'passed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_WAITLISTED = 'waitlisted';
    public const STATUS_RE_REGISTRATION = 're_registration';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW,
        self::STATUS_NEED_REVISION, self::STATUS_VERIFIED, self::STATUS_SELECTION,
        self::STATUS_PASSED, self::STATUS_FAILED, self::STATUS_WAITLISTED,
        self::STATUS_RE_REGISTRATION, self::STATUS_COMPLETED, self::STATUS_CANCELLED,
    ];

    public const PAYMENT_UNPAID = 'unpaid';
    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_FAILED = 'failed';
    public const PAYMENT_EXPIRED = 'expired';
    public const PAYMENT_CANCELLED = 'cancelled';
    public const PAYMENT_REFUNDED = 'refunded';

    public const PAYMENT_STATUSES = [
        self::PAYMENT_UNPAID, self::PAYMENT_PENDING, self::PAYMENT_PAID,
        self::PAYMENT_FAILED, self::PAYMENT_EXPIRED, self::PAYMENT_CANCELLED, self::PAYMENT_REFUNDED,
    ];

    public const DOCUMENT_NOT_UPLOADED = 'not_uploaded';
    public const DOCUMENT_UNDER_REVIEW = 'under_review';
    public const DOCUMENT_APPROVED = 'approved';
    public const DOCUMENT_REJECTED = 'rejected';

    public const DOCUMENT_STATUSES = [
        self::DOCUMENT_NOT_UPLOADED, self::DOCUMENT_UNDER_REVIEW,
        self::DOCUMENT_APPROVED, self::DOCUMENT_REJECTED,
    ];

    /**
     * Perpindahan status pendaftaran yang diizinkan.
     *
     * Catatan: `submitted` → `under_review` dan `under_review` → `verified` /
     * `need_revision` adalah alur verifikasi; `passed` hanya boleh setelah
     * `selection`, dan `completed` hanya setelah daftar ulang.
     *
     * `failed` / `waitlisted` boleh kembali ke `selection` karena sanggahan yang
     * diterima membuka keputusan untuk diputus ulang panitia
     * (`PpdbAppealService::decide()`). Yang dibuka adalah statusnya saja —
     * `selection_result` lama tetap terpasang sampai panitia menetapkan hasil
     * baru lewat `PpdbAnnouncementService::setResult()` (tetap dijaga kuota).
     * `passed` sengaja tidak punya jalur mundur: kursi sudah terisi.
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SUBMITTED, self::STATUS_CANCELLED],
        self::STATUS_SUBMITTED => [self::STATUS_UNDER_REVIEW, self::STATUS_NEED_REVISION, self::STATUS_CANCELLED],
        self::STATUS_UNDER_REVIEW => [self::STATUS_VERIFIED, self::STATUS_NEED_REVISION, self::STATUS_CANCELLED],
        self::STATUS_NEED_REVISION => [self::STATUS_SUBMITTED, self::STATUS_CANCELLED],
        self::STATUS_VERIFIED => [self::STATUS_SELECTION, self::STATUS_CANCELLED],
        self::STATUS_SELECTION => [self::STATUS_PASSED, self::STATUS_FAILED, self::STATUS_WAITLISTED],
        self::STATUS_PASSED => [self::STATUS_RE_REGISTRATION],
        self::STATUS_WAITLISTED => [self::STATUS_PASSED, self::STATUS_FAILED, self::STATUS_SELECTION],
        self::STATUS_RE_REGISTRATION => [self::STATUS_COMPLETED],
        self::STATUS_FAILED => [self::STATUS_SELECTION],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    /** Status yang sudah final (tidak boleh diubah lagi). */
    public const FINAL_STATUSES = [self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    /** Status di mana data pendaftaran boleh diubah calon. */
    public const EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_NEED_REVISION];

    protected $casts = [
        'submitted_at' => 'datetime',
        'verified_at' => 'datetime',
        'announced_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PpdbPeriod::class, 'period_id');
    }

    public function wave(): BelongsTo
    {
        return $this->belongsTo(PpdbWave::class, 'wave_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(PpdbProgram::class, 'program_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(PpdbApplicant::class, 'applicant_id');
    }

    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(PpdbParentAccount::class, 'parent_account_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(PpdbRegistrationAnswer::class, 'registration_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PpdbApplicantDocument::class, 'registration_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(PpdbRegistrationHistory::class, 'registration_id')->orderByDesc('id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(PpdbInvoice::class, 'registration_id');
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(PpdbRegistrationAppeal::class, 'registration_id')->orderByDesc('id');
    }

    public function selectionParticipants(): HasMany
    {
        return $this->hasMany(PpdbSelectionParticipant::class, 'registration_id');
    }

    public function scopeEditable(Builder $query): Builder
    {
        return $query->whereIn('status', self::EDITABLE_STATUSES);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES, true);
    }

    public function allowedTransitions(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function activityEventPrefix(): string
    {
        return 'PPDB_REGISTRATION';
    }
}