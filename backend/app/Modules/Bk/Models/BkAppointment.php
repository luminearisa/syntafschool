<?php

namespace App\Modules\Bk\Models;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Modules\Bk\Domain\Enums\AppointmentStatus;
use App\Modules\Bk\Domain\Enums\AttendanceStatus;
use App\Modules\Bk\Domain\Enums\SessionType;
use App\Modules\Bk\Domain\Enums\SessionMode;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Modules\Bk\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Janji temu konseling — termasuk deteksi bentrok jadwal
(akademik, konselor, siswa, asrama, ujian, kegiatan sekolah).
 *
 * Tabel: `bk_appointments`
 */
class BkAppointment extends BkModel
{
    protected $table = 'bk_appointments';

    use BelongsToSchool;
    use SoftDeletes;

    protected $casts = [
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'cancelled_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'conflict_details' => 'array',
        'status' => \App\Modules\Bk\Domain\Enums\AppointmentStatus::class,
        'attendance_status' => \App\Modules\Bk\Domain\Enums\AttendanceStatus::class,
        'session_type' => \App\Modules\Bk\Domain\Enums\SessionType::class,
        'mode' => \App\Modules\Bk\Domain\Enums\SessionMode::class,
        'confidentiality_level' => \App\Modules\Bk\Domain\Enums\ConfidentialityLevel::class,
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(BkCase::class, 'case_id');
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(BkReferral::class, 'referral_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function counselor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counselor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(BkAppointment::class, 'rescheduled_from_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(BkCounselingSession::class, 'appointment_id');
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereIn('status', ['SCHEDULED', 'CONFIRMED'])
            ->where('scheduled_start', '>=', now());
    }

    public function scopeForCounselor(Builder $query, int $userId): Builder
    {
        return $query->where('counselor_id', $userId);
    }
}
