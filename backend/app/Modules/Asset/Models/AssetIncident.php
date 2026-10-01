<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_incidents` — modul Asset & Sarpras.
 */
class AssetIncident extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_incidents';

    protected $fillable = [
        'incident_number',
        'asset_id',
        'facility_id',
        'incident_type',
        'status',
        'severity',
        'occurred_at',
        'reported_by',
        'description',
        'investigation_notes',
        'responsible_party',
        'resolution_type',
        'resolution',
        'recovered_value',
        'resolved_at',
        'resolved_by',
        'document_reference',
    ];

    protected $casts = [
        'status' => \App\Modules\Asset\Domain\Enums\IncidentStatus::class,
        'incident_type' => \App\Modules\Asset\Domain\Enums\IncidentType::class,
        'severity' => \App\Modules\Asset\Domain\Enums\DamageSeverity::class,
        'resolution_type' => \App\Modules\Asset\Domain\Enums\LostResolution::class,
        'occurred_at' => 'datetime',
        'recovered_value' => 'decimal:2',
        'resolved_at' => 'datetime',
    ];

    public function asset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function facility(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function reporter(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'reported_by');
    }
}
