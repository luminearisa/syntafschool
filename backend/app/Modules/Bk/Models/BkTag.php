<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Label kasus (mis. "kecemasan ujian", "sering terlambat") untuk analitik non-sensitif.
 *
 * Tabel: `bk_tags`
 */
class BkTag extends BkModel
{
    protected $table = 'bk_tags';

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function cases(): BelongsToMany
    {
        return $this->belongsToMany(BkCase::class, 'bk_case_tags', 'tag_id', 'case_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
