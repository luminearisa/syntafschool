<?php

namespace App\Modules\SchoolOperations\Transportation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DriverProfile extends Model
{
    use SoftDeletes;
    protected $table = 'driver_profiles';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'employee_id' => 'integer',
        'user_id' => 'integer',
        'campus_id' => 'integer',
        'license_expiry' => 'date',
        'max_working_minutes_per_day' => 'integer',
        'joined_at' => 'date',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(VehicleProfile::class, 'assigned_vehicle_id');
    }
    public function licenses(): HasMany
    {
        return $this->hasMany(DriverLicense::class, 'driver_profile_id');
    }
    public function qualifications(): HasMany
    {
        return $this->hasMany(DriverQualification::class, 'driver_profile_id');
    }
    public function trainings(): HasMany
    {
        return $this->hasMany(DriverTrainingRecord::class, 'driver_profile_id');
    }
    public function availabilityWindows(): HasMany
    {
        return $this->hasMany(DriverAvailabilityWindow::class, 'driver_profile_id');
    }
    public function assignments(): HasMany
    {
        return $this->hasMany(DriverAssignment::class, 'driver_profile_id');
    }
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'driver_profile_id');
    }
}
