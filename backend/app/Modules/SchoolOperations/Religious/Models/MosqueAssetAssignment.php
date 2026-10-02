<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `mosque_asset_assignments`.
 */
class MosqueAssetAssignment extends Model
{
    protected $table = 'mosque_asset_assignments';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'mosque_id' => 'integer',
        'area_id' => 'integer',
        'asset_id' => 'integer',
        'assigned_at' => 'date',
        'released_at' => 'date',
        'assigned_by' => 'integer',
        'quantity' => 'integer',
        'meta' => 'array',
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
