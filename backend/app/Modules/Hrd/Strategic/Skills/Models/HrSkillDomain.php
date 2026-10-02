<?php

declare(strict_types=1);

namespace App\Modules\Hrd\Strategic\Skills\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Hrd\Strategic\Skills\Models\HrSkillCategory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrSkillDomain extends Model
{
    protected $table = 'hr_skill_domains';

    protected $guarded = [];

    protected $casts = [
        'code' => 'string',
        'name' => 'string',
        'description' => 'string',
        'parent_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(HrSkillDomain::class, 'parent_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(HrSkillCategory::class, 'domain_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(HrSkillDomain::class, 'parent_id');
    }
}
