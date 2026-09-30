<?php

namespace App\Modules\CBT\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CbtBlueprintRule extends CbtModel
{
    protected $table = 'cbt_blueprint_rules';

    protected $casts = [
        'count' => 'integer',
        'points' => 'decimal:2',
        'position' => 'integer',
    ];

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(CbtBlueprint::class, 'blueprint_id');
    }
}
