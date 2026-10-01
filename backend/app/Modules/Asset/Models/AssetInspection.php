<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_inspections` — modul Asset & Sarpras.
 */
class AssetInspection extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_inspections';

    protected $fillable = [
        'inspection_number',
        'asset_id',
        'facility_id',
        'template_id',
        'inspection_type',
        'status',
        'scheduled_at',
        'performed_at',
        'performed_by',
        'result_summary',
        'score',
        'findings',
        'condition_found',
        'follow_up_required',
        'work_order_id',
        'document_reference',
        'notes',
    ];

    protected $casts = [
        'status' => \App\Modules\Asset\Domain\Enums\InspectionStatus::class,
        'inspection_type' => \App\Modules\Asset\Domain\Enums\InspectionType::class,
        'scheduled_at' => 'datetime',
        'performed_at' => 'datetime',
        'score' => 'decimal:2',
        'follow_up_required' => 'boolean',
    ];

    public function asset(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function facility(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function template(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AssetInspectionTemplate::class, 'template_id');
    }

    public function performer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'performed_by');
    }

    public function results(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetInspectionResult::class, 'inspection_id');
    }
}
