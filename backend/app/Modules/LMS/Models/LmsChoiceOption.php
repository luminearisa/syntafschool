<?php

namespace App\Modules\LMS\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pilihan pada polling (§38). */
class LmsChoiceOption extends BaseModel
{
    protected $table = 'lms_choice_options';

    protected $casts = [
        'limit' => 'integer',
        'sort_order' => 'integer',
    ];

    public function choice(): BelongsTo
    {
        return $this->belongsTo(LmsChoice::class, 'choice_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(LmsChoiceResponse::class, 'option_id');
    }
}
