<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `khutbahs`.
 */
class Khutbah extends Model
{
    protected $table = 'khutbahs';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'category_id' => 'integer',
        'khatib_id' => 'integer',
        'mosque_id' => 'integer',
        'prayer_date' => 'date',
        'duration_minutes' => 'integer',
        'document_id' => 'integer',
        'version_number' => 'integer',
        'created_by' => 'integer',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
        'delivered_at' => 'datetime',
        'meta' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(KhutbahCategory::class, 'category_id');
    }

    public function khatib(): BelongsTo
    {
        return $this->belongsTo(KhatibProfile::class, 'khatib_id');
    }

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }

    public function fridayPrayers(): HasMany
    {
        return $this->hasMany(FridayPrayer::class, 'khutbah_id');
    }

    public function khutbahVersions(): HasMany
    {
        return $this->hasMany(KhutbahVersion::class, 'khutbah_id');
    }
}
