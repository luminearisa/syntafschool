<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Fasilitas (ruang/aula/lapangan) yang dapat dipakai bersama, dibooking, dan ditutup sementara.
 */
class Facility extends Model
{
    use BelongsToSchool;

    protected $table = 'facilities';

    protected $fillable = [
        'campus_id',
        'location_id',
        'facility_type_id',
        'facility_code',
        'name',
        'capacity_total',
        'capacity_normal',
        'capacity_exam',
        'capacity_event',
        'status',
        'availability',
        'condition',
        'safety_status',
        'maintenance_status',
        'responsible_unit_id',
        'manager_user_id',
        'operational_hours',
        'min_booking_minutes',
        'max_booking_minutes',
        'advance_booking_days',
        'requires_approval',
        'allowed_roles',
        'utilization_rate',
        'booking_count',
        'description',
    ];

    protected $casts = [
        'status' => \App\Modules\Asset\Domain\Enums\FacilityStatus::class,
        'condition' => \App\Modules\Asset\Domain\Enums\AssetCondition::class,
        'operational_hours' => 'array',
        'requires_approval' => 'boolean',
        'allowed_roles' => 'array',
        'utilization_rate' => 'decimal:2',
    ];

    public function type(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FacilityType::class, 'facility_type_id');
    }

    public function location(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function campus(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function manager(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'manager_user_id');
    }

    public function responsibleUnit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Unit::class, 'responsible_unit_id');
    }

    public function bookings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FacilityBooking::class);
    }

    public function operatingHours(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FacilityOperatingHour::class);
    }

    public function blackoutPeriods(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FacilityBlackoutPeriod::class);
    }

    public function documents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FacilityDocument::class);
    }

    public function attributeValues(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FacilityAttributeValue::class);
    }

    public function maintenanceRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
    }

    public function utilization(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FacilityUtilizationDaily::class);
    }
}
