<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel `asset_assignments` — modul Asset & Sarpras.
 */
class AssetAssignment extends Model
{
    use BelongsToSchool;

    protected $table = 'asset_assignments';

    protected $fillable = [
        'assignment_number',
        'type',
        'status',
        'requested_by',
        'unit_id',
        'location_id',
        'custodian_id',
        'purpose',
        'start_date',
        'end_date',
        'approved_by',
        'approved_at',
        'acknowledged_at',
        'acknowledgement_condition',
        'acknowledgement_notes',
        'document_reference',
        'notes',
    ];

    protected $casts = [
        'status' => \App\Modules\Asset\Domain\Enums\AssignmentStatus::class,
        'type' => \App\Modules\Asset\Domain\Enums\AssignmentType::class,
        'start_date' => 'date',
        'end_date' => 'date',
        'approved_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetAssignmentItem::class, 'assignment_id');
    }

    public function requester(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'requested_by');
    }

    public function custodian(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AssetCustodian::class, 'custodian_id');
    }

    public function location(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
