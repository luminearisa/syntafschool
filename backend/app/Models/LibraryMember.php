<?php

namespace App\Models;

use App\Models\Concerns\LogsLibraryActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Keanggotaan perpustakaan.
 *
 * Menautkan orang yang sudah ada (siswa/pegawai/wali/user) — biodata tidak
 * diinput ulang (Rule 11) — atau menyimpan identitas mandiri untuk anggota
 * umum/alumni. Akun pengguna **tidak wajib** untuk keanggotaan fisik (§37).
 *
 * Satu orang hanya boleh memiliki satu keanggotaan aktif per perpustakaan
 * (Rule 7); penegakannya di `MembershipService`.
 */
class LibraryMember extends BaseModel
{
    use LogsLibraryActivity, SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_EXPIRED,
        self::STATUS_SUSPENDED,
        self::STATUS_BLOCKED,
        self::STATUS_INACTIVE,
    ];

    /** Status yang dianggap masih berlaku untuk transaksi. */
    public const ACTIVE_STATUSES = [self::STATUS_ACTIVE];

    protected $casts = [
        'birth_date' => 'date:Y-m-d',
        'registered_at' => 'date:Y-m-d',
        'valid_from' => 'date:Y-m-d',
        'valid_until' => 'date:Y-m-d',
    ];

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function membershipType(): BelongsTo
    {
        return $this->belongsTo(LibraryMembershipType::class, 'membership_type_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(LibraryMemberCard::class, 'member_id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(LibraryLoan::class, 'member_id');
    }

    public function fines(): HasMany
    {
        return $this->hasMany(LibraryFine::class, 'member_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(LibraryReservation::class, 'member_id');
    }

    /** Jumlah pinjaman yang masih berjalan (aktif atau terlambat). */
    public function openLoansCount(): int
    {
        return $this->loans()->open()->count();
    }

    /** Total denda yang belum dibayar. */
    public function outstandingFineAmount(): float
    {
        return (float) $this->fines()->outstanding()
            ->sum(\Illuminate\Support\Facades\DB::raw('amount - paid_amount - waived_amount'));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    /** Jenis tautan orang: student | employee | guardian | user | public. */
    public function linkedType(): string
    {
        return match (true) {
            $this->student_id !== null => 'student',
            $this->employee_id !== null => 'employee',
            $this->guardian_id !== null => 'guardian',
            $this->user_id !== null => 'user',
            default => 'public',
        };
    }

    /** Nama tampil: dari entitas tertaut, atau identitas mandiri. */
    public function getDisplayNameAttribute(): ?string
    {
        if (filled($this->name)) {
            return $this->name;
        }

        foreach (['student', 'employee', 'guardian', 'user'] as $relation) {
            if ($this->relationLoaded($relation) && $this->{$relation} !== null) {
                return $this->{$relation}->full_name ?? $this->{$relation}->name ?? null;
            }
        }

        return null;
    }

    /** Keanggotaan lewat tanggal berlaku (dipakai evaluasi status). */
    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    public function isWithinValidity(?Carbon $date = null): bool
    {
        $date ??= Carbon::now();

        if ($this->valid_from !== null && $this->valid_from->isAfter($date)) {
            return false;
        }

        return $this->valid_until === null || $this->valid_until->isFuture() || $this->valid_until->isSameDay($date);
    }

    public function activityEventPrefix(): string
    {
        return 'MEMBER';
    }
}