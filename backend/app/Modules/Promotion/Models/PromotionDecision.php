<?php

namespace App\Modules\Promotion\Models;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Keputusan kenaikan/tinggal satu siswa pada satu tahun ajaran.
 *
 * `snapshot` menyimpan potret kriteria saat keputusan diambil dan sengaja tidak
 * pernah dihitung ulang — nilai, kehadiran, dan poin pelanggaran bisa berubah
 * setelahnya, tetapi dasar keputusannya harus tetap dapat diaudit.
 */
class PromotionDecision extends PromotionModel
{
    use SoftDeletes;

    public const DECISION_NAIK = 'naik';
    public const DECISION_TINGGAL = 'tinggal';
    public const DECISION_LULUS = 'lulus';
    public const DECISION_TIDAK_LULUS = 'tidak_lulus';

    /** @var list<string> */
    public const DECISIONS = [
        self::DECISION_NAIK,
        self::DECISION_TINGGAL,
        self::DECISION_LULUS,
        self::DECISION_TIDAK_LULUS,
    ];

    /** @var array<string, string> */
    public const DECISION_LABELS = [
        self::DECISION_NAIK => 'Naik kelas',
        self::DECISION_TINGGAL => 'Tinggal kelas',
        self::DECISION_LULUS => 'Lulus',
        self::DECISION_TIDAK_LULUS => 'Tidak lulus',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_FINALIZED = 'finalized';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_REVIEWED,
        self::STATUS_APPROVED,
        self::STATUS_FINALIZED,
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_REVIEWED => 'Diajukan',
        self::STATUS_APPROVED => 'Disetujui',
        self::STATUS_FINALIZED => 'Final',
    ];

    protected $table = 'promotion_decisions';

    protected $casts = [
        'snapshot' => 'array',
        'decided_at' => 'datetime',
        'decision' => 'string',
        'status' => 'string',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** Kelas asal siswa saat keputusan diambil. */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'school_class_id');
    }

    /** Kelas tujuan untuk keputusan `naik`. */
    public function targetClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'target_class_id');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(PromotionApproval::class);
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }

    public function isLocked(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_FINALIZED], true);
    }
}
