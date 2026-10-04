<?php

namespace App\Modules\StudentProgress\Models;

use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Peringatan akademik / early warning (tabel `academic_alerts`).
 *
 * Modul StudentProgress memakai tipe `pelanggaran` untuk menandai siswa yang
 * total poin pelanggarannya melewati ambang. Alert tidak pernah dihapus keras
 * (tabel tanpa softDeletes): riwayat peringatan tetap terbaca, penyelesaian
 * cukup dengan mengisi `resolved_at`/`resolved_by`.
 */
class AcademicAlert extends ProgressModel
{
    protected $table = 'academic_alerts';

    public const TYPE_LOW_GRADE = 'nilai_rendah';

    public const TYPE_LOW_ATTENDANCE = 'kehadiran_rendah';

    public const TYPE_PROMOTION_RISK = 'risiko_tidak_naik';

    public const TYPE_VIOLATION = 'pelanggaran';

    public const LEVEL_INFO = 'info';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_CRITICAL = 'critical';

    /** @var array<string, string> */
    public const LEVEL_LABELS = [
        self::LEVEL_INFO => 'Informasi',
        self::LEVEL_WARNING => 'Peringatan',
        self::LEVEL_CRITICAL => 'Kritis',
    ];

    protected $casts = [
        'data' => 'array',
        'triggered_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function levelLabel(): string
    {
        return self::LEVEL_LABELS[$this->level] ?? (string) $this->level;
    }
}
