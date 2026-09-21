<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jam operasional mingguan per perpustakaan.
 *
 * Satu hari boleh memiliki beberapa baris, sehingga beberapa interval dalam
 * sehari (mis. 08:00–12:00 dan 13:00–16:00) dapat dinyatakan tanpa mengubah
 * skema. Baris `is_open = false` berarti hari tersebut tutup.
 */
class LibraryOperatingHour extends BaseModel
{
    public const DAY_LABELS = [
        0 => 'Ahad',
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'is_open' => 'boolean',
        'open_time' => 'datetime:H:i',
        'close_time' => 'datetime:H:i',
    ];

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function dayLabel(): string
    {
        return self::DAY_LABELS[$this->day_of_week] ?? (string) $this->day_of_week;
    }
}