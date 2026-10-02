<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model tabel `mosque_maintenance_requests`.
 */
class MosqueMaintenanceRequest extends Model
{
    protected $table = 'mosque_maintenance_requests';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'mosque_id' => 'integer',
        'area_id' => 'integer',
        'asset_id' => 'integer',
        'equipment_id' => 'integer',
        'reported_by' => 'integer',
        'reported_at' => 'datetime',
        'resolved_at' => 'datetime',
        'cost' => 'decimal:2',
    ];

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(MosqueArea::class, 'area_id');
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(MosqueEquipment::class, 'equipment_id');
    }
}
