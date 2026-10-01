<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `damage_reports` — modul Asset & Sarpras.
 */
class DamageReport extends Model
{
    use BelongsToSchool;

    protected $table = 'damage_reports';

    protected $fillable = [
        'report_number',
        'asset_id',
        'facility_id',
        'location_id',
        'reported_by',
        'reported_at',
        'severity',
        'status',
        'damage_type',
        'description',
        'photo_path',
        'verified_by',
        'verified_at',
        'inspection_id',
        'maintenance_request_id',
        'resolution',
        'resolved_at',
        'notes',
    ];

    protected $casts = [
        'status' => \App\Modules\Asset\Domain\Enums\DamageReportStatus::class,
        'severity' => \App\Modules\Asset\Domain\Enums\DamageSeverity::class,
        'reported_at' => 'datetime',
        'verified_at' => 'datetime',
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

    public function maintenanceRequest(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }
}
