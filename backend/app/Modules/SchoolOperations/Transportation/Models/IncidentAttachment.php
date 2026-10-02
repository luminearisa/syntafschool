<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentAttachment extends Model
{

    protected $table = 'incident_attachments';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'incident_id' => 'integer',
        'uploaded_by' => 'integer',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(TransportationIncident::class, 'incident_id');
    }
}
