<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use App\Modules\Supervisi\Domain\Enums\InstrumentCategory;
use App\Modules\Supervisi\Domain\Enums\InstrumentTargetType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Pustaka instrumen supervisi (wadah versi). */
class SupInstrument extends BaseModel
{
    use SoftDeletes;

    protected $table = 'sup_instruments';

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'category' => InstrumentCategory::class,
        'target_type' => InstrumentTargetType::class,
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(SupInstrumentVersion::class, 'instrument_id')->orderByDesc('version');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(SupInstrumentVersion::class, 'current_version_id');
    }

    public function latestPublished(): ?SupInstrumentVersion
    {
        return $this->versions()->where('status', 'PUBLISHED')->orderByDesc('version')->first();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
