<?php

namespace App\Modules\Hrd\Talent\Models;

use App\Modules\Hrd\Models\HrdModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Hrd\Models\HrEmployee;

/**
 * Phase 2 — tabel `hr_recognitions` (domain Talent).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrRecognition extends HrdModel
{
    protected $table = 'hr_recognitions';

    protected $guarded = [];
    protected $casts = [
        'is_public' => 'boolean',
        'recognized_at' => 'datetime',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
