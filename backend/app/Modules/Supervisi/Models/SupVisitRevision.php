<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Riwayat revisi nilai/kegiatan (finalisasi, pembukaan ulang, skor ulang). */
class SupVisitRevision extends BaseModel
{
    protected $table = 'sup_visit_revisions';

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'revision' => 'integer',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(SupVisit::class, 'visit_id');
    }
}
