<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `mosque_equipment`.
 */
class MosqueEquipment extends Model
{
    protected $table = 'mosque_equipment';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'mosque_id' => 'integer',
        'area_id' => 'integer',
        'asset_id' => 'integer',
        'quantity' => 'integer',
        'last_checked_at' => 'datetime',
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

    public function mosqueMaintenanceRequests(): HasMany
    {
        return $this->hasMany(MosqueMaintenanceRequest::class, 'equipment_id');
    }
}
