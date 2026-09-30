<?php

namespace App\Modules\CBT\Models;

use App\Models\Subject;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CbtBlueprint extends CbtModel
{
    use SoftDeletes;

    protected $table = 'cbt_blueprints';

    protected $casts = [
        'shuffle_questions' => 'boolean',
        'shuffle_options' => 'boolean',
        'duration_minutes' => 'integer',
        'passing_score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'question_count' => 'integer',
        'negative_marking' => 'boolean',
        'negative_fraction' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(CbtBlueprintRule::class, 'blueprint_id')->orderBy('position');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CbtBlueprintItem::class, 'blueprint_id')->orderBy('position');
    }
}
