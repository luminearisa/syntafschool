<?php

namespace App\Modules\Hrd\Talent\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Talent\Models\HrRecognition;

/**
 * Phase 2 — tabel `hr_recognition_awards` (domain Talent).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrRecognitionAward extends HrdModel
{
    protected $table = 'hr_recognition_awards';

    protected $guarded = [];
    protected $casts = [
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function recognitions(): HasMany
    {
        return $this->hasMany(HrRecognition::class, 'award_id');
    }
}
