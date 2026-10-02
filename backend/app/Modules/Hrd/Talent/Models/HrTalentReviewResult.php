<?php

namespace App\Modules\Hrd\Talent\Models;

use App\Modules\Hrd\Models\HrdModel;
use App\Modules\Hrd\Models\HrEmployee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Hrd\Talent\Models\HrTalentReview;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Phase 2 — tabel `hr_talent_review_results` (domain Talent).
 *
 * Nama tabel disetel eksplisit sehingga penggantian nama kelas atau pemindahan
 * berkas tidak pernah mengubah tabel yang dibaca.
 */
class HrTalentReviewResult extends HrdModel
{
    protected $table = 'hr_talent_review_results';

    protected $guarded = [];
    protected $casts = [
        'performance_rating' => 'decimal:2',
        'potential_rating' => 'decimal:2',
        'development_actions' => 'array',
        'decided_at' => 'datetime',
        'meta' => 'array',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(HrTalentReview::class, 'review_id');
    }

    /** Pegawai pemilik baris ini. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
