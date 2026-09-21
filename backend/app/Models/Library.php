<?php

namespace App\Models;

use App\Models\Concerns\LogsLibraryActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu perpustakaan.
 *
 * Sistem tidak mengasumsikan hanya ada satu perpustakaan — sekolah dapat
 * memiliki beberapa (SMP, SMA, umum), karena itu seluruh konfigurasi
 * (settings, lokasi, jam operasional, penutupan, penomoran) ber-scope ke sini.
 */
class Library extends BaseModel
{
    use LogsLibraryActivity, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_ARCHIVED,
    ];

    /** Perpindahan status yang diizinkan. Draft wajib melewati `active`. */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_ACTIVE, self::STATUS_ARCHIVED],
        self::STATUS_ACTIVE => [self::STATUS_INACTIVE, self::STATUS_ARCHIVED],
        self::STATUS_INACTIVE => [self::STATUS_ACTIVE, self::STATUS_ARCHIVED],
        // Arsip bersifat final agar data historis tidak berubah-ubah.
        self::STATUS_ARCHIVED => [],
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(LibrarySetting::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(LibraryLocation::class);
    }

    public function operatingHours(): HasMany
    {
        return $this->hasMany(LibraryOperatingHour::class);
    }

    public function closures(): HasMany
    {
        return $this->hasMany(LibraryClosure::class);
    }

    public function numberingConfigs(): HasMany
    {
        return $this->hasMany(LibraryNumberingConfig::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /** Status tujuan yang boleh dipilih dari status saat ini. */
    public function allowedTransitions(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function activityEventPrefix(): string
    {
        return 'LIBRARY';
    }
}