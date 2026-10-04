<?php

namespace App\Modules\Promotion\Models;

use App\Models\AcademicYear;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Keputusan kelulusan satu siswa pada satu tahun ajaran.
 *
 * `criteria_summary` menyimpan potret perhitungan (nilai rata-rata, kehadiran,
 * poin pelanggaran, mapel tidak tuntas) saat kelulusan diputuskan.
 */
class Graduation extends PromotionModel
{
    use SoftDeletes;

    public const DECISION_LULUS = 'lulus';
    public const DECISION_TIDAK_LULUS = 'tidak_lulus';

    /** @var list<string> */
    public const DECISIONS = [
        self::DECISION_LULUS,
        self::DECISION_TIDAK_LULUS,
    ];

    /** @var array<string, string> */
    public const DECISION_LABELS = [
        self::DECISION_LULUS => 'Lulus',
        self::DECISION_TIDAK_LULUS => 'Tidak lulus',
    ];

    protected $table = 'graduations';

    protected $casts = [
        'criteria_summary' => 'array',
        'average_score' => 'decimal:2',
        'approved_at' => 'datetime',
        'decision' => 'string',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }
}
