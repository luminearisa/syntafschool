<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sesi coaching (PMM: diskusi persiapan/tindak lanjut) beserta komitmen. */
class SupCoachingSession extends BaseModel
{
    protected $table = 'sup_coaching_sessions';

    protected $casts = [
        'session_at' => 'datetime',
        'follow_up_at' => 'datetime',
        'commitments' => 'array',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(SupVisit::class, 'visit_id');
    }
}
