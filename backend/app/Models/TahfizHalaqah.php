<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kelompok/halaqah Tahfiz: sekumpulan peserta satu level yang belajar bersama
 * satu instruktur (dan asisten/musyrif).
 *
 * Kapasitasnya dibatasi dan jumlah anggota aktifnya dicek saat menambah
 * peserta, agar halaqah tidak kelebihan muatan.
 */
class TahfizHalaqah extends BaseModel
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_MERGED = 'merged';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_MERGED];

    protected $casts = [
        'capacity' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(TahfizProgram::class, 'program_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(TahfizLevel::class, 'level_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'instructor_id');
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assistant_id');
    }

    public function musyrif(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'musyrif_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(TahfizHalaqahMember::class, 'halaqah_id');
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', TahfizHalaqahMember::STATUS_ACTIVE);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(TahfizSchedule::class, 'halaqah_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TahfizSession::class, 'halaqah_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(TahfizEnrollment::class, 'halaqah_id');
    }

    /** Jumlah anggota aktif saat ini. */
    public function activeMembersCount(): int
    {
        return $this->activeMembers()->count();
    }

    /** True bila halaqah masih menerima peserta baru. */
    public function hasCapacity(): bool
    {
        return $this->activeMembersCount() < $this->capacity;
    }
}
