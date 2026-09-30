<?php

namespace App\Modules\Clinic\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lokasi layanan kesehatan (UKS Putra/Putri, klinik utama, klinik asrama).
 *
 * Tabel: `clinic_clinics`
 */
class ClinicLocation extends ClinicModel
{
    /** Nama tabel eksplisit: kelas tidak selalu sama dengan bentuk jamak Inggris. */
    protected $table = 'clinic_clinics';

    use SoftDeletes;

    protected $casts = [
        'operating_hours' => 'array',
        'is_default' => 'boolean',
        'supports_observation' => 'boolean',
        'supports_emergency' => 'boolean',
        'bed_capacity' => 'integer',
    ];

    public function staff(): HasMany
    {
        return $this->hasMany(ClinicStaff::class, 'clinic_id')->where('status', 'ACTIVE');
    }
    public function visits(): HasMany
    {
        return $this->hasMany(ClinicVisit::class, 'clinic_id');
    }
    public function queueEntries(): HasMany
    {
        return $this->hasMany(ClinicQueueEntry::class, 'clinic_id');
    }
    public function observations(): HasMany
    {
        return $this->hasMany(ClinicObservation::class, 'clinic_id');
    }
    public function screenings(): HasMany
    {
        return $this->hasMany(ClinicScreening::class, 'clinic_id');
    }
    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'ACTIVE');
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first()
            ?? static::query()->where('status', 'ACTIVE')->orderBy('id')->first();
    }
}
