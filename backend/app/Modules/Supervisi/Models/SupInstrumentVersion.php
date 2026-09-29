<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use App\Modules\Supervisi\Domain\Enums\InstrumentVersionStatus;
use App\Modules\Supervisi\Domain\Enums\ScoringMode;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versi instrumen.
 *
 * Versi yang sudah PUBLISHED bersifat beku: definisi indikator/bobot/ambang
 * tidak boleh diubah supaya nilai yang sudah dihitung tetap sahih. Perubahan
 * dilakukan dengan mengkloning versi baru.
 */
class SupInstrumentVersion extends BaseModel
{
    protected $table = 'sup_instrument_versions';

    protected $casts = [
        'status' => InstrumentVersionStatus::class,
        'scoring_mode' => ScoringMode::class,
        'max_score' => 'float',
        'min_passing_score' => 'float',
        'requires_action_plan' => 'boolean',
        'allows_multi_source' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(SupInstrument::class, 'instrument_id');
    }

    public function gradeScale(): BelongsTo
    {
        return $this->belongsTo(SupGradeScale::class, 'grade_scale_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(SupSection::class, 'version_id')->orderBy('sort_order');
    }

    public function indicators(): HasMany
    {
        return $this->hasMany(SupIndicator::class, 'version_id');
    }

    public function isEditable(): bool
    {
        return $this->status === InstrumentVersionStatus::DRAFT;
    }
}
