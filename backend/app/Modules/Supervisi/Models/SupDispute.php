<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Keberatan/tanggapan atas hasil supervisi, diselesaikan moderator. */
class SupDispute extends BaseModel
{
    protected $table = 'sup_disputes';

    protected $casts = [
        'proposed_scores' => 'array',
        'attachments' => 'array',
        'decided_at' => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(SupVisit::class, 'visit_id');
    }
}
