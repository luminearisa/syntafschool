<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sertifikat Tahfiz — selesai program, hafal 1/5/10/30 juz, lulus tasmi',
 * atau lulus tahsin.
 *
 * `qr_token` memungkinkan verifikasi publik tanpa login: endpoint verifikasi
 * mencari sertifikat berdasarkan token dan menampilkan data tanpa membocorkan
 * informasi sensitif lainnya.
 */
class TahfizCertificate extends BaseModel
{
    use SoftDeletes;

    public const ACHIEVEMENT_SELESAI_PROGRAM = 'selesai_program';
    public const ACHIEVEMENT_HAFAL_1_JUZ = 'hafal_1_juz';
    public const ACHIEVEMENT_HAFAL_5_JUZ = 'hafal_5_juz';
    public const ACHIEVEMENT_HAFAL_10_JUZ = 'hafal_10_juz';
    public const ACHIEVEMENT_HAFAL_30_JUZ = 'hafal_30_juz';
    public const ACHIEVEMENT_LULUS_TASMI = 'lulus_tasmi';
    public const ACHIEVEMENT_LULUS_TAHSIN = 'lulus_tahsin';

    /** @var list<string> */
    public const ACHIEVEMENT_TYPES = [
        self::ACHIEVEMENT_SELESAI_PROGRAM, self::ACHIEVEMENT_HAFAL_1_JUZ,
        self::ACHIEVEMENT_HAFAL_5_JUZ, self::ACHIEVEMENT_HAFAL_10_JUZ,
        self::ACHIEVEMENT_HAFAL_30_JUZ, self::ACHIEVEMENT_LULUS_TASMI,
        self::ACHIEVEMENT_LULUS_TAHSIN,
    ];

    /** Jumlah juz untuk prestasi hafalan, agar pelaporan konsisten. */
    public const ACHIEVEMENT_JUZ = [
        self::ACHIEVEMENT_HAFAL_1_JUZ => 1,
        self::ACHIEVEMENT_HAFAL_5_JUZ => 5,
        self::ACHIEVEMENT_HAFAL_10_JUZ => 10,
        self::ACHIEVEMENT_HAFAL_30_JUZ => 30,
    ];

    public const STATUS_ISSUED = 'issued';
    public const STATUS_REVOKED = 'revoked';

    protected $casts = [
        'issue_date' => 'date:Y-m-d',
        'revoked_at' => 'datetime',
    ];

    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ISSUED);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(TahfizParticipant::class, 'participant_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(TahfizEnrollment::class, 'enrollment_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(TahfizProgram::class, 'program_id');
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(TahfizExam::class, 'exam_id');
    }

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'examiner_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issuer_id');
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }
}
