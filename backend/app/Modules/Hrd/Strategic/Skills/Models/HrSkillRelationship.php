<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Skills\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Models\HrSkill;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrSkillRelationship extends Model
{
    protected $table = 'hr_skill_relationships';

    protected $guarded = [];

    protected $casts = [
        'skill_id' => 'integer',
        'related_skill_id' => 'integer',
        'relationship_type' => 'string',
        'strength' => 'decimal:2',
        'notes' => 'string',
        'meta' => 'array',
    ];

    public function skill(): BelongsTo
    {
        return $this->belongsTo(HrSkill::class, 'skill_id');
    }

    public function relatedSkill(): BelongsTo
    {
        return $this->belongsTo(HrSkill::class, 'related_skill_id');
    }
}
