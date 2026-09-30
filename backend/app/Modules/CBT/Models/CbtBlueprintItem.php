<?php

namespace App\Modules\CBT\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CbtBlueprintItem extends CbtModel
{
    protected $table = 'cbt_blueprint_items';

    protected $casts = [
        'points' => 'decimal:2',
        'position' => 'integer',
        'required' => 'boolean',
    ];

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(CbtBlueprint::class, 'blueprint_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(CbtQuestion::class, 'question_id');
    }
}
