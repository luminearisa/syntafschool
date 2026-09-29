<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Temuan analisis data supervisi: kelebihan, kelemahan, masalah, faktor
 * penyebab, prioritas perbaikan, dan rekomendasi.
 */
class SupFinding extends BaseModel
{
    protected $table = 'sup_findings';

    public function visit(): BelongsTo
    {
        return $this->belongsTo(SupVisit::class, 'visit_id');
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(SupIndicator::class, 'indicator_id');
    }
}
