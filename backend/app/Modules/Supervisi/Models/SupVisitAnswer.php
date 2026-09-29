<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Jawaban satu indikator pada satu kegiatan supervisi. */
class SupVisitAnswer extends BaseModel
{
    protected $table = 'sup_visit_answers';

    protected $casts = [
        'score' => 'float',
        'value_number' => 'float',
        'value_date' => 'date',
        'value_json' => 'array',
        'is_na' => 'boolean',
        'answered_at' => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(SupVisit::class, 'visit_id');
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(SupIndicator::class, 'indicator_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(SupIndicatorOption::class, 'option_id');
    }
}
