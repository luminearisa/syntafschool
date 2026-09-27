<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Izin keluar asrama (§28): sakit ke klinik, pulang dijenguk keluarga, acara
 * sekolah, atau keluar biasa.
 *
 * Kepulangan reguler TIDAK dicatat di sini — itu ranah `boarding_homecomings`
 * (§30), karena masa kepulangan tidak diukur dalam jam dan tidak lewat gerbang
 * pada jam tertentu.
 *
 * Alur status: draft → submitted → (approved|rejected) → out → returned.
 * `expired` dan `cancelled` menutup dari mana saja. Jumlah langkah approval
 * dikontrol konfigurasi modul (`permission_approval_steps`), sehingga
 * `current_step`/`required_steps` selalu bisa dibandingkan tanpa membaca
 * settings di tiap query (§27).
 */
class BoardingPermission extends BaseModel
{
    use LogsBoardingActivity;

    public const TYPE_SICK = 'sick';

    public const TYPE_FAMILY = 'family';

    public const TYPE_SCHOOL_ACTIVITY = 'school_activity';

    public const TYPE_OUT = 'out';

    public const TYPE_SPECIAL = 'special';

    public const TYPES = [
        self::TYPE_SICK, self::TYPE_FAMILY, self::TYPE_SCHOOL_ACTIVITY,
        self::TYPE_OUT, self::TYPE_SPECIAL,
    ];

    public const TYPE_LABELS = [
        self::TYPE_SICK => 'Sakit (keluar periksa)',
        self::TYPE_FAMILY => 'Kunjungan keluarga',
        self::TYPE_SCHOOL_ACTIVITY => 'Kegiatan sekolah',
        self::TYPE_OUT => 'Keluar biasa',
        self::TYPE_SPECIAL => 'Istimewa',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_OUT = 'out';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_APPROVED,
        self::STATUS_REJECTED, self::STATUS_OUT, self::STATUS_RETURNED,
        self::STATUS_EXPIRED, self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_SUBMITTED => 'Menunggu Persetujuan',
        self::STATUS_APPROVED => 'Disetujui',
        self::STATUS_REJECTED => 'Ditolak',
        self::STATUS_OUT => 'Keluar',
        self::STATUS_RETURNED => 'Sudah Kembali',
        self::STATUS_EXPIRED => 'Kedaluwarsa',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    /** Status yang masih menunggu tindakan petugas (antrean kerja). */
    public const PENDING_STATUSES = [self::STATUS_SUBMITTED];

    /** Status yang membuat santri dianggap berada di luar asrama (§42). */
    public const OUT_STATUSES = [self::STATUS_APPROVED, self::STATUS_OUT];

    /** Status yang masih terbuka (belum kembali/dibatalkan/kedaluwarsa). */
    public const OPEN_STATUSES = [
        self::STATUS_SUBMITTED, self::STATUS_APPROVED, self::STATUS_OUT,
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'expected_return_at' => 'datetime',
        'actual_return_at' => 'datetime',
        'late_return' => 'boolean',
        'current_step' => 'integer',
        'required_steps' => 'integer',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(BoardingPermissionApproval::class, 'permission_id')->orderBy('step');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function checkedOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    /** Sedang di luar asrama — dipakai layanan status (§42). */
    public function scopeCurrentlyOut(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OUT);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }

    public function scopeForStudent(Builder $query, ?int $studentId): Builder
    {
        return $studentId === null ? $query : $query->where('student_id', $studentId);
    }

    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        return ($type === null || $type === '') ? $query : $query->where('permission_type', $type);
    }

    public function scopeLateReturners(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OUT)
            ->where('expected_return_at', '<', now());
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isOut(): bool
    {
        return $this->status === self::STATUS_OUT;
    }

    /** Semua langkah persetujuan sudah terlampaui? (§27) */
    public function isFullyApproved(): bool
    {
        return $this->current_step >= max(1, (int) $this->required_steps);
    }

    /** Langkah berikutnya, atau null bila sudah lengkap. */
    public function nextStep(): ?int
    {
        return $this->isFullyApproved() ? null : $this->current_step + 1;
    }

    public function hasOverdueReturn(): bool
    {
        return $this->isOut() && $this->expected_return_at !== null && $this->expected_return_at->isPast();
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->permission_type] ?? $this->permission_type;
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_PERMISSION';
    }
}
