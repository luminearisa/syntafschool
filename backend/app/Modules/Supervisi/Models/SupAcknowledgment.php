<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tanda “sudah dibaca/diterima” oleh yang disupervisi (dasar hak tanggapan). */
class SupAcknowledgment extends BaseModel
{
    protected $table = 'sup_acknowledgments';

    protected $casts = [
        'acknowledged_at' => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(SupVisit::class, 'visit_id');
    }
}
