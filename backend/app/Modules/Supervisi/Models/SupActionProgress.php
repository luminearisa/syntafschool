<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Laporan kemajuan sebuah langkah tindak lanjut. */
class SupActionProgress extends BaseModel
{
    protected $table = 'sup_action_progress';

    protected $casts = [
        'reported_at' => 'datetime',
        'progress_percent' => 'integer',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(SupActionItem::class, 'action_item_id');
    }
}
