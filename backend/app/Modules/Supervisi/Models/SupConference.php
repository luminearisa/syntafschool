<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Wawancara pra-observasi (PRE) atau pasca-observasi (POST). */
class SupConference extends BaseModel
{
    protected $table = 'sup_conferences';

    protected $casts = [
        'held_at' => 'datetime',
        'participants' => 'array',
        'answers' => 'array',
        'agreements' => 'array',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(SupVisit::class, 'visit_id');
    }
}
