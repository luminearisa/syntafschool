<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleDocument extends Model
{

    protected $table = 'vehicle_documents';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'vehicle_id' => 'integer',
        'issue_date' => 'date',
        'expiry_date' => 'date',
        'verified_by' => 'integer',
        'verified_at' => 'datetime',
        'blocks_dispatch' => 'boolean',
        'reminder_days' => 'integer',
        'reminder_sent_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(VehicleProfile::class, 'vehicle_id');
    }
}
