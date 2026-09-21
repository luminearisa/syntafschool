<?php

namespace App\Models;

use App\Models\Concerns\LogsLibraryActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lokasi di dalam perpustakaan (hierarkis: gedung → lantai → ruang).
 *
 * Rak buku tidak ditangani di sini — itu bagian modul katalog/inventaris.
 */
class LibraryLocation extends BaseModel
{
    use LogsLibraryActivity;

    public const TYPE_BUILDING = 'building';
    public const TYPE_FLOOR = 'floor';
    public const TYPE_ROOM = 'room';
    public const TYPE_READING_AREA = 'reading_area';
    public const TYPE_STORAGE = 'storage';
    public const TYPE_SERVICE_DESK = 'service_desk';
    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_BUILDING,
        self::TYPE_FLOOR,
        self::TYPE_ROOM,
        self::TYPE_READING_AREA,
        self::TYPE_STORAGE,
        self::TYPE_SERVICE_DESK,
        self::TYPE_OTHER,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** Seluruh keturunan (rekursif) — dipakai untuk mencegah siklus saat pindah. */
    public function descendantIds(): array
    {
        $ids = [];
        $stack = $this->children()->pluck('id')->all();

        while ($stack !== []) {
            $id = array_pop($stack);
            $ids[] = (int) $id;
            $stack = array_merge($stack, self::query()->where('parent_id', $id)->pluck('id')->all());
        }

        return $ids;
    }

    public function activityEventPrefix(): string
    {
        return 'LIBRARY_LOCATION';
    }
}