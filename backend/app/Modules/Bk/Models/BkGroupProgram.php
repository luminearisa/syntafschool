<?php

namespace App\Modules\Bk\Models;

use App\Models\User;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Program kelompok BK.
 *
 * Program adalah layanan pencegahan dan pengembangan, jadi ia tidak menuntut
 * adanya kasus pada pesertanya.
 *
 * Tabel: `bk_group_programs`
 */
class BkGroupProgram extends BkModel
{
    protected $table = 'bk_group_programs';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'requires_consent' => 'boolean',
        'evaluation' => 'array',
        'program_type' => \App\Modules\Bk\Domain\Enums\GroupProgramType::class,
        'status' => \App\Modules\Bk\Domain\Enums\GroupProgramStatus::class,
        'confidentiality_level' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
    ];

    public function sessions(): HasMany
    {
        return $this->hasMany(BkGroupProgramSession::class, 'program_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(BkGroupProgramMember::class, 'program_id');
    }

    public function facilitator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'facilitator_user_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['DRAFT', 'PLANNED', 'ACTIVE', 'ON_HOLD']);
    }
}
