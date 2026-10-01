<?php

namespace App\Modules\Activity\Infrastructure\Providers;

use App\Models\AcademicYear;
use App\Models\Semester;
use App\Modules\Activity\Domain\Contracts\AcademicProviderInterface;
use App\Modules\Activity\Domain\Contracts\StudentProviderInterface;
use App\Modules\Activity\Infrastructure\Providers\EloquentStudentProvider;
use App\Modules\Activity\Models\ActivityMembership;
use App\Modules\Activity\Models\ActivityProgress;

/**
 * Konteks akademik dari SIAKAD, dan usulan pengakuan aktivitas (§56).
 *
 * Yang penting di sini: modul Activity **tidak menulis nilai akademik**. Ia
 * menyiapkan usulan (dengan rekap kehadiran, nilai aktivitas, dan prestasi) yang
 * siap diproses modul akademik melalui workflow — sehingga angka yang masuk
 * rapor tetap dikendalikan SIAKAD.
 */
class LocalAcademicProvider implements AcademicProviderInterface
{
    public function __construct(private readonly StudentProviderInterface $students) {}

    public function context(): array
    {
        $year = AcademicYear::query()->where('is_active', true)->first();
        $semester = Semester::query()->where('is_active', true)->first();

        return [
            'academic_year_id' => $year?->id === null ? null : (int) $year->id,
            'academic_year' => $year?->name,
            'semester_id' => $semester?->id === null ? null : (int) $semester->id,
            'semester' => $semester?->name,
            'start_date' => $year?->start_date?->toDateString(),
            'end_date' => $year?->end_date?->toDateString(),
        ];
    }

    public function classLevel(int $studentId): ?int
    {
        return $this->students->currentClass($studentId)['level'] ?? null;
    }

    public function programName(int $studentId): ?string
    {
        return $this->students->snapshot($studentId)['program'] ?? null;
    }

    public function standing(int $studentId): array
    {
        $progress = ActivityProgress::query()
            ->where('student_id', $studentId)
            ->latest('calculated_at')
            ->first();

        return [
            'activity_average_score' => $progress?->average_score === null ? null : (float) $progress->average_score,
            'activity_attendance_percentage' => $progress?->attendance_percentage === null ? null : (float) $progress->attendance_percentage,
            'source' => 'activity',
        ];
    }

    public function submitRecognition(int $studentId, array $payload): array
    {
        $membershipId = $payload['membership_id'] ?? null;

        $membership = $membershipId === null ? null : ActivityMembership::query()->find($membershipId);

        if ($membership === null) {
            return [
                'accepted' => false,
                'reason' => 'Keanggotaan tidak ditemukan — usulan pengakuan tidak dapat disiapkan.',
            ];
        }

        return [
            'accepted' => true,
            'status' => 'QUEUED',
            'student_id' => $studentId,
            'membership_id' => (int) $membership->id,
            // Usulan ini menjadi bahan modul akademik; pengubahan nilai resmi
            // tetap lewat workflow penilaian milik SIAKAD.
            'proposal' => $payload,
        ];
    }

    public function reportCardSummary(int $studentId, ?int $periodId = null): array
    {
        $memberships = ActivityMembership::query()
            ->with(['program:id,name,minimum_attendance_percentage', 'progress'])
            ->where('student_id', $studentId)
            ->when($periodId !== null, fn ($query) => $query->where('period_id', $periodId))
            ->get();

        return [
            'student_id' => $studentId,
            'period_id' => $periodId,
            'activities' => $memberships->map(fn (ActivityMembership $membership) => [
                'program' => $membership->program?->name,
                'role' => $membership->role?->value,
                'status' => $membership->status?->value,
                'attendance_percentage' => $membership->progress?->attendance_percentage === null
                    ? null
                    : (float) $membership->progress->attendance_percentage,
                'average_score' => $membership->progress?->average_score === null
                    ? null
                    : (float) $membership->progress->average_score,
                'skills' => $membership->progress?->skills ?? [],
            ])->all(),
        ];
    }
}
