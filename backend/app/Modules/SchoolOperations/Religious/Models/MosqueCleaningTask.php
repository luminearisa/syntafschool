<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `mosque_cleaning_tasks`.
 */
class MosqueCleaningTask extends Model
{
    protected $table = 'mosque_cleaning_tasks';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'mosque_id' => 'integer',
        'area_id' => 'integer',
        'scheduled_date' => 'date',
        'assigned_to' => 'integer',
        'completed_at' => 'datetime',
        'inspected_by' => 'integer',
        'inspected_at' => 'datetime',
    ];

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(MosqueArea::class, 'area_id');
    }
}
