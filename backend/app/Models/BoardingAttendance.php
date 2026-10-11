<?php

namespace App\Models;

use App\Models\Concerns\LogsBoardingActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu catatan absensi santri pada satu kegiatan, tanggal, dan sesi (§17, §24).
 *
 * Kunci uniknya `['student_id', 'activity_id', 'date', 'session']`: absensi
 * ganda ditolak di level database DAN di service. Koreksi tidak menimpa nilai
 * lama secara diam-diam — `revision` dinaikkan, `revised_by`/`revised_at`
 * diisi, dan jejak lengkapnya ada di `activity_log` (§26).
 *
 * Sumber data dibedakan (`manual`/`bulk`/`rfid`) karena angka hasil scan RFID
 * perlu diperlakukan berbeda saat rekap: waktunya nyata, tetapi tipe absennya
 * tetap harus dikonfirmasi pengasuh.
 */
class BoardingAttendance extends BaseModel
{
    use LogsBoardingActivity;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_BULK = 'bulk';

    public const SOURCE_RFID = 'rfid';

    public const SOURCE_QR = 'qr';

    public const SOURCES = [self::SOURCE_MANUAL, self::SOURCE_BULK, self::SOURCE_QR, self::SOURCE_RFID];

    public const SOURCE_LABELS = [
        self::SOURCE_MANUAL => 'Manual',
        self::SOURCE_BULK => 'Massal',
        self::SOURCE_QR => 'Scan QR',
        self::SOURCE_RFID => 'Scan RFID',
    ];

    public const DEFAULT_SESSION = '1';

    protected $casts = [
        'date' => 'date',
        'recorded_at' => 'datetime',
        'revised_at' => 'datetime',
        'is_late' => 'boolean',
        'late_minutes' => 'integer',
        'revision' => 'integer',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(BoardingActivity::class, 'activity_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BoardingActivitySchedule::class, 'schedule_id');
    }

    public function attendanceType(): BelongsTo
    {
        return $this->belongsTo(BoardingAttendanceType::class, 'attendance_type_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function revisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revised_by');
    }

    public function scopeOnDate(Builder $query, ?string $date): Builder
    {
        return ($date === null || $date === '') ? $query : $query->whereDate('date', $date);
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->whereDate('boarding_attendances.date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('boarding_attendances.date', '<=', $to));
    }

    public function scopeForStudent(Builder $query, ?int $studentId): Builder
    {
        return $studentId === null ? $query : $query->where('student_id', $studentId);
    }

    public function scopeForActivity(Builder $query, ?int $activityId): Builder
    {
        return $activityId === null ? $query : $query->where('activity_id', $activityId);
    }

    /** Baris yang dihitung hadir, apa pun kode tipe absennya (§17). */
    public function scopePresent(Builder $query): Builder
    {
        return $query->whereHas('attendanceType', fn (Builder $q) => $q->where('is_present', true));
    }

    public function scopeLate(Builder $query): Builder
    {
        return $query->where('is_late', true);
    }

    public function scopeFromRfid(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_RFID);
    }

    /**
     * Sudah pernah ada catatan untuk kombinasi kunci unik ini? Dipakai service
     * sebelum insert agar pesan galatnya manusiawi, bukan error database.
     */
    public static function existsFor(int $studentId, int $activityId, string $date, string $session = self::DEFAULT_SESSION): bool
    {
        return self::query()
            ->where('student_id', $studentId)
            ->where('activity_id', $activityId)
            ->whereDate('date', $date)
            ->where('session', $session)
            ->exists();
    }

    public function countsAsPresent(): bool
    {
        return (bool) $this->attendanceType?->is_present;
    }

    public function isCorrected(): bool
    {
        return (int) $this->revision > 1;
    }

    public function activityEventPrefix(): string
    {
        return 'BOARDING_ATTENDANCE';
    }
}
