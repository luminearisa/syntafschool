<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use App\Modules\Supervisi\Domain\Enums\AppraisalSource;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nilai dari satu sumber (diri sendiri, atasan, rekan, siswa) — dasar 360°. */
class SupAppraisalSource extends BaseModel
{
    protected $table = 'sup_appraisal_sources';

    protected $casts = [
        'source' => AppraisalSource::class,
        'weight' => 'float',
        'score' => 'float',
        'submitted_at' => 'datetime',
    ];

    public function appraisal(): BelongsTo
    {
        return $this->belongsTo(SupAppraisal::class, 'appraisal_id');
    }
}
