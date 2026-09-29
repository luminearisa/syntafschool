<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Catatan fakta selama observasi (dapat bercap waktu untuk video/audio). */
class SupVisitNote extends BaseModel
{
    protected $table = 'sup_visit_notes';

    protected $casts = [
        'at_seconds' => 'integer',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(SupVisit::class, 'visit_id');
    }
}
