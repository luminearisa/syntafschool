<?php

namespace App\Modules\Hrd\Career\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Hrd\Career\Models\HrCareerPath;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_career_path_steps` (domain Career).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrCareerPathStep extends HrdModel
{
    protected $table = 'hr_career_path_steps';

    protected $guarded = [];
    protected $casts = [
        'min_years_experience' => 'decimal:2',
        'required_competencies' => 'array',
        'required_skills' => 'array',
        'is_active' => 'boolean',
    ];

    public function path(): BelongsTo
    {
        return $this->belongsTo(HrCareerPath::class, 'path_id');
    }
}
