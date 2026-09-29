<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use App\Modules\Supervisi\Domain\Enums\GradeTone;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Opsi jawaban untuk indikator bertipe SCALE / MULTI_CHOICE. */
class SupIndicatorOption extends BaseModel
{
    protected $table = 'sup_indicator_options';

    protected $casts = [
        'score' => 'float',
        'tone' => GradeTone::class,
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(SupIndicator::class, 'indicator_id');
    }
}
