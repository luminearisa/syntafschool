<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `ramadan_programs`.
 */
class RamadanProgram extends Model
{
    protected $table = 'ramadan_programs';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'gregorian_start' => 'date',
        'gregorian_end' => 'date',
        'campus_id' => 'integer',
        'mosque_id' => 'integer',
        'organization_id' => 'integer',
        'program_types' => 'array',
        'expected_participants' => 'integer',
        'budget' => 'decimal:2',
        'approved_by' => 'integer',
        'meta' => 'array',
    ];

    public function mosque(): BelongsTo
    {
        return $this->belongsTo(Mosque::class, 'mosque_id');
    }

    public function prayerAttendancePolicies(): HasMany
    {
        return $this->hasMany(PrayerAttendancePolicy::class, 'program_id');
    }

    public function religiousBoardingPrayerRules(): HasMany
    {
        return $this->hasMany(ReligiousBoardingPrayerRule::class, 'program_id');
    }

    public function ramadanDays(): HasMany
    {
        return $this->hasMany(RamadanDay::class, 'program_id');
    }

    public function ramadanActivities(): HasMany
    {
        return $this->hasMany(RamadanActivity::class, 'program_id');
    }

    public function tarawihSessions(): HasMany
    {
        return $this->hasMany(TarawihSession::class, 'program_id');
    }

    public function tahajjudSessions(): HasMany
    {
        return $this->hasMany(TahajjudSession::class, 'program_id');
    }

    public function iftarEvents(): HasMany
    {
        return $this->hasMany(IftarEvent::class, 'program_id');
    }

    public function suhoorEvents(): HasMany
    {
        return $this->hasMany(SuhoorEvent::class, 'program_id');
    }

    public function itikafPrograms(): HasMany
    {
        return $this->hasMany(ItikafProgram::class, 'program_id');
    }

    public function itikafParticipants(): HasMany
    {
        return $this->hasMany(ItikafParticipant::class, 'program_id');
    }

    public function qurbanAnimals(): HasMany
    {
        return $this->hasMany(QurbanAnimal::class, 'program_id');
    }

    public function qurbanDistributions(): HasMany
    {
        return $this->hasMany(QurbanDistribution::class, 'program_id');
    }
}
