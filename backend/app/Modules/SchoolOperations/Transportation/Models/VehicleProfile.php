<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleProfile extends Model
{
    use SoftDeletes;
    protected $table = 'vehicle_profiles';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'asset_id' => 'integer',
        'vehicle_type_id' => 'integer',
        'manufacture_year' => 'integer',
        'total_capacity' => 'integer',
        'student_capacity' => 'integer',
        'adult_capacity' => 'integer',
        'standing_capacity' => 'integer',
        'wheelchair_capacity' => 'integer',
        'reserved_capacity' => 'integer',
        'odometer' => 'decimal:2',
        'campus_id' => 'integer',
        'home_location_id' => 'integer',
        'ownership_type_id' => 'integer',
        'current_mileage_since_service' => 'integer',
        'last_service_date' => 'date',
        'status_changed_at' => 'datetime',
    ];

    public function vehicleTypeRef(): BelongsTo
    {
        return $this->belongsTo(TransportationVehicleType::class, 'vehicle_type_id');
    }
    public function equipment(): HasMany
    {
        return $this->hasMany(VehicleEquipment::class, 'vehicle_id');
    }
    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class, 'vehicle_id');
    }
    public function fuelLogs(): HasMany
    {
        return $this->hasMany(VehicleFuelLog::class, 'vehicle_id');
    }
    public function inspections(): HasMany
    {
        return $this->hasMany(VehicleInspection::class, 'vehicle_id');
    }
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'vehicle_id');
    }
    public function rentals(): HasMany
    {
        return $this->hasMany(VehicleRental::class, 'vehicle_id');
    }
}
