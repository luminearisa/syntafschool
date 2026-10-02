<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `friday_prayers`.
 */
class FridayPrayer extends Model
{
    protected $table = 'friday_prayers';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'mosque_id' => 'integer',
        'campus_id' => 'integer',
        'prayer_date' => 'date',
        'khatib_id' => 'integer',
        'imam_id' => 'integer',
        'muadzin_id' => 'integer',
        'estimated_attendance' => 'integer',
        'actual_attendance' => 'integer',
        'khutbah_id' => 'integer',
        'is_substitute' => 'boolean',
        'created_by' => 'integer',
        'meta' => 'array',
    ];

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }

    public function khatib(): BelongsTo
    {
        return $this->belongsTo(KhatibProfile::class, 'khatib_id');
    }

    public function imam(): BelongsTo
    {
        return $this->belongsTo(ImamProfile::class, 'imam_id');
    }

    public function muadzin(): BelongsTo
    {
        return $this->belongsTo(MuadzinProfile::class, 'muadzin_id');
    }

    public function khutbah(): BelongsTo
    {
        return $this->belongsTo(Khutbah::class, 'khutbah_id');
    }

    public function khatibAssignments(): HasMany
    {
        return $this->hasMany(KhatibAssignment::class, 'friday_prayer_id');
    }

    public function fridayPrayerAssignments(): HasMany
    {
        return $this->hasMany(FridayPrayerAssignment::class, 'friday_prayer_id');
    }
}
