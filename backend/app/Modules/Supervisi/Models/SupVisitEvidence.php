<?php

namespace App\Modules\Supervisi\Models;

use App\Models\BaseModel;
use App\Modules\Supervisi\Domain\Enums\EvidenceKind;
use App\Modules\Supervisi\Domain\Enums\VisibilityScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lampiran bukti (foto/video/dokumen/tautan) sebuah kegiatan supervisi. */
class SupVisitEvidence extends BaseModel
{
    protected $table = 'sup_visit_evidence';

    protected $casts = [
        'kind' => EvidenceKind::class,
        'visibility' => VisibilityScope::class,
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(SupVisit::class, 'visit_id');
    }

    public function answer(): BelongsTo
    {
        return $this->belongsTo(SupVisitAnswer::class, 'visit_answer_id');
    }
}
