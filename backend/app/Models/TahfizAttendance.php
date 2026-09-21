<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kehadiran peserta Tahfiz — terpisah dari presensi sekolah.
 *
 * Dapat dikaitkan dengan sesi konkret atau langsung dengan halaqah pada
 * tanggal tertentu. Status H/I/S/A/T.
 */
class TahfizAttendance extends BaseModel
{
    // Migrasi menamai tabel ini "tahfiz_attendance" (tunggal).
    protected $table = 'tahfiz_attendance';

    public const STATUS_HADIR = 'hadir';
    public const STATUS_IZIN = 'izin';
    public const STATUS_SAKIT = 'sakit';
    public const STATUS_ALPA = 'alpa';
    public const STATUS_TERLAMBAT = 'terlambat';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_HADIR, self::STATUS_IZIN, self::STATUS_SAKIT,
        self::STATUS_ALPA, self::STATUS_TERLAMBAT,
    ];

    /** Status yang dianggap "masuk" untuk perhitungan kehadiran. */
    public const PRESENT_STATUSES = [self::STATUS_HADIR, self::STATUS_TERLAMBAT];

    /** Singkatan untuk tombol cepat di antarmuka. */
    public const CODES = [
        self::STATUS_HADIR => 'H',
        self::STATUS_IZIN => 'I',
        self::STATUS_SAKIT => 'S',
        self::STATUS_ALPA => 'A',
        self::STATUS_TERLAMBAT => 'T',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'arrival_time' => 'datetime:H:i',
    ];

    public function scopePresent(Builder $query): Builder
    {
        return $query->whereIn('status', self::PRESENT_STATUSES);
    }

    public function scopeAbsent(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::PRESENT_STATUSES);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TahfizSession::class, 'session_id');
    }

    public function halaqah(): BelongsTo
    {
        return $this->belongsTo(TahfizHalaqah::class, 'halaqah_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TahfizParticipant::class, 'participant_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
