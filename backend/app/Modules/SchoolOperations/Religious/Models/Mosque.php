<?php

namespace App\Modules\SchoolOperations\Religious\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model tabel `mosques`.
 */
class Mosque extends Model
{
    protected $table = 'mosques';

    /** Kolom yang tidak boleh diisi massal. */
    protected $guarded = ['id'];

    protected $casts = [
        'campus_id' => 'integer',
        'capacity' => 'integer',
        'open_24h' => 'boolean',
        'active' => 'boolean',
        'meta' => 'array',
    ];

    public function mosqueAreas(): HasMany
    {
        return $this->hasMany(MosqueArea::class, 'mosque_id');
    }

    public function mosqueAccessRules(): HasMany
    {
        return $this->hasMany(MosqueAccessRule::class, 'mosque_id');
    }

    public function mosqueEquipment(): HasMany
    {
        return $this->hasMany(MosqueEquipment::class, 'mosque_id');
    }

    public function mosqueAssetAssignments(): HasMany
    {
        return $this->hasMany(MosqueAssetAssignment::class, 'mosque_id');
    }

    public function mosqueBookings(): HasMany
    {
        return $this->hasMany(MosqueBooking::class, 'mosque_id');
    }

    public function mosqueCleaningTasks(): HasMany
    {
        return $this->hasMany(MosqueCleaningTask::class, 'mosque_id');
    }

    public function mosqueMaintenanceRequests(): HasMany
    {
        return $this->hasMany(MosqueMaintenanceRequest::class, 'mosque_id');
    }

    public function mosqueUtilizationLogs(): HasMany
    {
        return $this->hasMany(MosqueUtilizationLog::class, 'mosque_id');
    }

    public function mosqueDonationCollections(): HasMany
    {
        return $this->hasMany(MosqueDonationCollection::class, 'mosque_id');
    }

    public function mosqueDisplaySettings(): HasMany
    {
        return $this->hasMany(MosqueDisplaySetting::class, 'mosque_id');
    }

    public function prayerSchedules(): HasMany
    {
        return $this->hasMany(PrayerSchedule::class, 'mosque_id');
    }

    public function prayerScheduleOverrides(): HasMany
    {
        return $this->hasMany(PrayerScheduleOverride::class, 'mosque_id');
    }

    public function congregationalPrayers(): HasMany
    {
        return $this->hasMany(CongregationalPrayer::class, 'mosque_id');
    }

    public function imamProfiles(): HasMany
    {
        return $this->hasMany(ImamProfile::class, 'mosque_id');
    }

    public function imamAssignments(): HasMany
    {
        return $this->hasMany(ImamAssignment::class, 'mosque_id');
    }

    public function muadzinProfiles(): HasMany
    {
        return $this->hasMany(MuadzinProfile::class, 'mosque_id');
    }

    public function muadzinAssignments(): HasMany
    {
        return $this->hasMany(MuadzinAssignment::class, 'mosque_id');
    }

    public function khatibAssignments(): HasMany
    {
        return $this->hasMany(KhatibAssignment::class, 'mosque_id');
    }

    public function religiousDutyRosters(): HasMany
    {
        return $this->hasMany(ReligiousDutyRoster::class, 'mosque_id');
    }

    public function religiousDutyAssignments(): HasMany
    {
        return $this->hasMany(ReligiousDutyAssignment::class, 'mosque_id');
    }

    public function prayerAttendanceRecords(): HasMany
    {
        return $this->hasMany(PrayerAttendanceRecord::class, 'mosque_id');
    }

    public function religiousDailyClosings(): HasMany
    {
        return $this->hasMany(ReligiousDailyClosing::class, 'mosque_id');
    }

    public function religiousActivities(): HasMany
    {
        return $this->hasMany(ReligiousActivity::class, 'mosque_id');
    }

    public function religiousActivitySessions(): HasMany
    {
        return $this->hasMany(ReligiousActivitySession::class, 'mosque_id');
    }

    public function fridayPrayers(): HasMany
    {
        return $this->hasMany(FridayPrayer::class, 'mosque_id');
    }

    public function khutbahs(): HasMany
    {
        return $this->hasMany(Khutbah::class, 'mosque_id');
    }

    public function ramadanPrograms(): HasMany
    {
        return $this->hasMany(RamadanProgram::class, 'mosque_id');
    }

    public function ramadanActivities(): HasMany
    {
        return $this->hasMany(RamadanActivity::class, 'mosque_id');
    }

    public function tarawihSessions(): HasMany
    {
        return $this->hasMany(TarawihSession::class, 'mosque_id');
    }

    public function tahajjudSessions(): HasMany
    {
        return $this->hasMany(TahajjudSession::class, 'mosque_id');
    }

    public function iftarEvents(): HasMany
    {
        return $this->hasMany(IftarEvent::class, 'mosque_id');
    }

    public function itikafPrograms(): HasMany
    {
        return $this->hasMany(ItikafProgram::class, 'mosque_id');
    }

    public function eidEvents(): HasMany
    {
        return $this->hasMany(EidEvent::class, 'mosque_id');
    }

    public function religiousAnnouncements(): HasMany
    {
        return $this->hasMany(ReligiousAnnouncement::class, 'mosque_id');
    }

    public function religiousAuditLogs(): HasMany
    {
        return $this->hasMany(ReligiousAuditLog::class, 'mosque_id');
    }
}
