<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Skor satu indikator dalam dokumen penilaian kinerja. */
class SupAppraisalScore extends BaseModel
{
    protected $table = 'sup_appraisal_scores';

    protected $casts = [
        'score' => 'float',
    ];

    public function appraisal(): BelongsTo
    {
        return $this->belongsTo(SupAppraisal::class, 'appraisal_id');
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(SupIndicator::class, 'indicator_id');
    }
}
