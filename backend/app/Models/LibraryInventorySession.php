<?php

namespace App\Models;

use App\Models\Concerns\LogsLibraryActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sesi stock opname.
 *
 * Alur: `draft` → `ongoing` (pindai) → `finalized`. Ringkasan hasil disimpan
 * saat finalisasi agar laporan tidak menghitung ulang seluruh baris.
 */
class LibraryInventorySession extends BaseModel
{
    use LogsLibraryActivity, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ONGOING = 'ongoing';
    public const STATUS_FINALIZED = 'finalized';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_ONGOING, self::STATUS_FINALIZED, self::STATUS_CANCELLED,
    ];

    protected $casts = [
        'started_at' => 'date:Y-m-d',
        'finalized_at' => 'datetime',
        'expected_count' => 'integer',
        'found_count' => 'integer',
        'missing_count' => 'integer',
        'unexpected_count' => 'integer',
        'damaged_count' => 'integer',
    ];

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(LibraryLocation::class, 'location_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LibraryInventoryLine::class, 'session_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_DRAFT, self::STATUS_ONGOING]);
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }

    public function activityEventPrefix(): string
    {
        return 'INVENTORY';
    }
}