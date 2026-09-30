<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Modules\Clinic\Domain\Enums\QueueStage;
use Illuminate\Database\Eloquent\Builder;

/**
 * Antrean harian per lokasi layanan; dipakai papan antrean dan ruang tunggu.
 *
 * Tabel: `clinic_queue_entries`
 */
class ClinicQueueEntry extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_queue_entries';

    protected $casts = [
        'queue_date' => 'date',
        'called_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'sequence' => 'integer',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'visit_id');
    }
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(ClinicLocation::class, 'clinic_id');
    }
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('stage', '!=', 'COMPLETED');
    }

    public function stageEnum(): QueueStage
    {
        return QueueStage::from($this->stage);
    }
}
