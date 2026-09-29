<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;

/** Kegiatan pengembangan kompetensi (diklat, workshop, coaching, lesson study). */
class SupDevelopment extends BaseModel
{
    protected $table = 'sup_developments';

    protected $casts = [
        'hours' => 'float',
        'cost' => 'float',
        'started_at' => 'date',
        'finished_at' => 'date',
    ];
}
