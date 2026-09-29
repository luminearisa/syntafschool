<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Bagian/komponen instrumen (mis. A. Pendahuluan, B. Kegiatan Inti). */
class SupSection extends BaseModel
{
    protected $table = 'sup_sections';

    protected $casts = [
        'weight' => 'float',
        'sort_order' => 'integer',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(SupInstrumentVersion::class, 'version_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function indicators(): HasMany
    {
        return $this->hasMany(SupIndicator::class, 'section_id')->orderBy('sort_order');
    }
}
