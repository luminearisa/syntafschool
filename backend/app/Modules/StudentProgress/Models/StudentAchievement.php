<?php

namespace App\Modules\StudentProgress\Models;

use App\Models\AchievementType;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Prestasi siswa (tabel `student_achievements`).
 *
 * `level` adalah kolom string bebas di skema; konstanta di sini hanya dipakai
 * sebagai daftar dropdown dan divalidasi pada permintaan masuk agar laporan
 * tidak pernah berisi jenjang yang tidak bisa diurutkan.
 */
class StudentAchievement extends ProgressModel
{
    use SoftDeletes;

    protected $table = 'student_achievements';

    /** @var list<string> */
    public const LEVELS = [
        'Sekolah',
        'Kecamatan',
        'Kabupaten',
        'Provinsi',
        'Nasional',
        'Internasional',
    ];

    /** @var array<string, string> */
    public const LEVEL_LABELS = [
        'Sekolah' => 'Sekolah',
        'Kecamatan' => 'Kecamatan',
        'Kabupaten' => 'Kabupaten',
        'Provinsi' => 'Provinsi',
        'Nasional' => 'Nasional',
        'Internasional' => 'Internasional',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function achievementType(): BelongsTo
    {
        return $this->belongsTo(AchievementType::class);
    }

    public function scopeForStudent(Builder $query, ?int $studentId): Builder
    {
        return $studentId === null ? $query : $query->where('student_id', $studentId);
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->whereDate('student_achievements.date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('student_achievements.date', '<=', $to));
    }

    public function levelLabel(): ?string
    {
        return $this->level === null ? null : (self::LEVEL_LABELS[$this->level] ?? $this->level);
    }
}
