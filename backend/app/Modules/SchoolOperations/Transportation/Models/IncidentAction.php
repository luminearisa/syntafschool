<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentAction extends Model
{

    protected $table = 'incident_actions';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'incident_id' => 'integer',
        'performed_by' => 'integer',
        'performed_at' => 'datetime',
        'payload' => 'array',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(TransportationIncident::class, 'incident_id');
    }
}
