<?php

namespace App\Models;

use App\Models\Concerns\LogsLibraryActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penutupan khusus perpustakaan: libur nasional, libur sekolah, maintenance,
 * acara sekolah, atau hari khusus lain.
 *
 * Jam boleh dikosongkan (tutup sehari penuh) atau diisi untuk menutup
 * sebagian hari saja.
 */
class LibraryClosure extends BaseModel
{
    use LogsLibraryActivity;

    public const TYPE_NATIONAL_HOLIDAY = 'national_holiday';
    public const TYPE_SCHOOL_HOLIDAY = 'school_holiday';
    public const TYPE_MAINTENANCE = 'maintenance';
    public const TYPE_SCHOOL_EVENT = 'school_event';
    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_NATIONAL_HOLIDAY,
        self::TYPE_SCHOOL_HOLIDAY,
        self::TYPE_MAINTENANCE,
        self::TYPE_SCHOOL_EVENT,
        self::TYPE_OTHER,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_CANCELLED];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
    ];

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function activityEventPrefix(): string
    {
        return 'LIBRARY_CLOSURE';
    }
}