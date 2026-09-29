<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sasaran program supervisi (unit, mapel, jenis pegawai). */
class SupProgramTarget extends BaseModel
{
    protected $table = 'sup_program_targets';

    protected $casts = [
        'planned_count' => 'integer',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(SupProgram::class, 'program_id');
    }
}
