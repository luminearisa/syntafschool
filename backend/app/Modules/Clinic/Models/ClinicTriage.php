<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Modules\Clinic\Domain\Enums\Priority;
use Illuminate\Database\Eloquent\Builder;

/**
 * Triase: keluhan utama, prioritas, dan penilaian awal.
 *
 * Tabel: `clinic_triages`
 */
class ClinicTriage extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_triages';

    protected $casts = [
        'associated_symptoms' => 'array',
        'triaged_at' => 'datetime',
        'is_infectious_suspect' => 'boolean',
        'requires_isolation' => 'boolean',
        'pain_score' => 'integer',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'visit_id');
    }
    public function triagedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triaged_by');
    }
    public function priorityEnum(): Priority
    {
        return Priority::from($this->priority);
    }
}
