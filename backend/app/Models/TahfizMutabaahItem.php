<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Item mutabaah harian yang dapat dikonfigurasi admin.
 *
 * Item TIDAK ditulis keras di kode: "Tilawah 1 juz", "Murojaah", "Hafalan
 * baru", "Shalat berjamaah" semuanya adalah baris di tabel ini, sehingga admin
 * dapat menambah/mengubah/menonaktifkannya tanpa merilis ulang aplikasi.
 */
class TahfizMutabaahItem extends BaseModel
{
    public const CATEGORY_QURAN = 'quran';
    public const CATEGORY_WORSHIP = 'worship';
    public const CATEGORY_ACTIVITY = 'activity';
    public const CATEGORY_OTHER = 'other';

    /** @var list<string> */
    public const CATEGORIES = [
        self::CATEGORY_QURAN, self::CATEGORY_WORSHIP, self::CATEGORY_ACTIVITY, self::CATEGORY_OTHER,
    ];

    public const VALUE_BOOLEAN = 'boolean';
    public const VALUE_COUNT = 'count';

    protected $casts = [
        'target_value' => 'integer',
        'order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('order')->orderBy('id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TahfizMutabaahEntry::class, 'item_id');
    }
}
