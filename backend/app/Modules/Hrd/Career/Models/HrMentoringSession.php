<?php

namespace App\Modules\Hrd\Career\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_mentoring_sessions` (domain Career).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrMentoringSession extends HrdModel
{
    protected $table = 'hr_mentoring_sessions';

    protected $guarded = [];
    protected $casts = [
        'session_date' => 'date:Y-m-d',
        'action_items' => 'array',
        'meta' => 'array',
    ];

}
