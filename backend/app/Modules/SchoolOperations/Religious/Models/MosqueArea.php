<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `mosque_areas`.
 */
class MosqueArea extends Model
{
    protected $table = 'mosque_areas';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'mosque_id' => 'integer',
        'capacity' => 'integer',
        'bookable' => 'boolean',
        'meta' => 'array',
    ];

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }

    public function mosqueAccessRules(): HasMany
    {
        return $this->hasMany(MosqueAccessRule::class, 'area_id');
    }

    public function mosqueEquipment(): HasMany
    {
        return $this->hasMany(MosqueEquipment::class, 'area_id');
    }

    public function mosqueAssetAssignments(): HasMany
    {
        return $this->hasMany(MosqueAssetAssignment::class, 'area_id');
    }

    public function mosqueBookings(): HasMany
    {
        return $this->hasMany(MosqueBooking::class, 'area_id');
    }

    public function mosqueCleaningTasks(): HasMany
    {
        return $this->hasMany(MosqueCleaningTask::class, 'area_id');
    }

    public function mosqueMaintenanceRequests(): HasMany
    {
        return $this->hasMany(MosqueMaintenanceRequest::class, 'area_id');
    }

    public function mosqueUtilizationLogs(): HasMany
    {
        return $this->hasMany(MosqueUtilizationLog::class, 'area_id');
    }

    public function religiousDutyAssignments(): HasMany
    {
        return $this->hasMany(ReligiousDutyAssignment::class, 'area_id');
    }

    public function religiousActivities(): HasMany
    {
        return $this->hasMany(ReligiousActivity::class, 'area_id');
    }

    public function religiousActivitySessions(): HasMany
    {
        return $this->hasMany(ReligiousActivitySession::class, 'area_id');
    }

    public function ramadanActivities(): HasMany
    {
        return $this->hasMany(RamadanActivity::class, 'area_id');
    }
}
