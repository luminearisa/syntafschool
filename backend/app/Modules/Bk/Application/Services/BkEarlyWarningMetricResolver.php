<?php

namespace App\Modules\Bk\Application\Services;

use App\Modules\Bk\Domain\Contracts\AcademicProviderInterface;
use App\Modules\Bk\Domain\Contracts\AttendanceProviderInterface;
use App\Modules\Bk\Domain\Contracts\BoardingProviderInterface;
use App\Modules\Bk\Domain\Contracts\ClinicSupportProviderInterface;
use App\Modules\Bk\Domain\Contracts\DisciplineProviderInterface;
use App\Modules\Bk\Domain\Enums\EarlyWarningMetric;
use App\Modules\Bk\Domain\Enums\FollowUpStatus;
use App\Modules\Bk\Models\BkFollowUp;
use App\Modules\Bk\Models\BkReferral;
use Carbon\CarbonInterface;

/**
 * Pengambil nilai metrik untuk mesin aturan.
 *
 * Semua angka diambil dari **modul pemiliknya** lewat kontrak, bukan dari salinan
 * di tabel BK. Bila sebuah metrik tidak tersedia (mis. sekolah belum memasang
 * modul asrama), resolver mengembalikan `null` — dan mesin mencatatnya sebagai
 * "tidak dievaluasi" dengan alasan yang terbaca, bukan diam-diam dianggap nol.
 * Angka nol yang salah lebih berbahaya daripada ketiadaan yang jujur.
 */
class BkEarlyWarningMetricResolver
{
    public function __construct(
        private readonly AttendanceProviderInterface $attendance,
        private readonly AcademicProviderInterface $academic,
        private readonly DisciplineProviderInterface $discipline,
        private readonly BoardingProviderInterface $boarding,
        private readonly ClinicSupportProviderInterface $clinic,
    ) {
    }

    /**
     * @return array{value: float|null, unit: string|null, reason?: string}
     */
    public function resolve(EarlyWarningMetric $metric, int $studentId, CarbonInterface $from, CarbonInterface $to): array
    {
        try {
            return match ($metric) {
                EarlyWarningMetric::ATTENDANCE_RATE => $this->fromAttendance($studentId, $from, $to, 'percentage'),
                EarlyWarningMetric::ABSENCE_COUNT => $this->fromAttendance($studentId, $from, $to, 'absent'),
                EarlyWarningMetric::LATE_COUNT => $this->fromAttendance($studentId, $from, $to, 'late'),
                EarlyWarningMetric::DISCIPLINE_COUNT => $this->fromDiscipline($studentId, $from, $to, 'incidents'),
                EarlyWarningMetric::DISCIPLINE_POINTS => $this->fromDiscipline($studentId, $from, $to, 'points'),
                EarlyWarningMetric::ACADEMIC_AVERAGE => $this->fromAcademic($studentId, 'average'),
                EarlyWarningMetric::SUBJECT_BELOW_THRESHOLD_COUNT => $this->fromAcademic($studentId, 'below_minimum'),
                EarlyWarningMetric::ACADEMIC_CHANGE => $this->academicChange($studentId, $from, $to),
                EarlyWarningMetric::REFERRAL_COUNT => $this->referralCount($studentId, $from, $to),
                EarlyWarningMetric::FOLLOW_UP_FAILURE_COUNT => $this->followUpFailures($studentId, $from, $to),
                EarlyWarningMetric::CLINIC_VISIT_COUNT => $this->clinicVisits($studentId),
                EarlyWarningMetric::BOARDING_ADJUSTMENT_COUNT => $this->boardingAdjustments($studentId),
                EarlyWarningMetric::ACTIVITY_DISENGAGEMENT_DAYS => [
                    'value' => null,
                    'unit' => 'DAY',
                    'reason' => 'Sinyal keterlibatan kegiatan hanya tersedia sebagai ringkasan, bukan durasi hari.',
                ],
            };
        } catch (\Throwable $exception) {
            // Satu modul yang bermasalah tidak boleh menghentikan seluruh evaluasi.
            return ['value' => null, 'unit' => null, 'reason' => 'Metrik gagal dibaca: '.$exception->getMessage()];
        }
    }

    /** @return array{value: float|null, unit: string|null, reason?: string} */
    private function fromAttendance(int $studentId, CarbonInterface $from, CarbonInterface $to, string $key): array
    {
        $summary = $this->attendance->summary($studentId, $from->toDateString(), $to->toDateString());
        $value = $summary[$key] ?? null;

        return [
            'value' => is_numeric($value) ? (float) $value : null,
            'unit' => $key === 'percentage' ? 'PERCENT' : 'COUNT',
            'reason' => is_numeric($value) ? null : 'Presensi belum memiliki data pada rentang ini.',
        ];
    }

    /** @return array{value: float|null, unit: string|null, reason?: string} */
    private function fromDiscipline(int $studentId, CarbonInterface $from, CarbonInterface $to, string $key): array
    {
        $summary = $this->discipline->summary($studentId, $from->toDateString(), $to->toDateString());
        $value = $summary[$key] ?? null;

        return ['value' => is_numeric($value) ? (float) $value : null, 'unit' => 'COUNT'];
    }

    /** @return array{value: float|null, unit: string|null, reason?: string} */
    private function fromAcademic(int $studentId, string $key): array
    {
        $summary = $this->academic->summary($studentId);
        $value = $summary[$key] ?? null;

        return [
            'value' => is_numeric($value) ? (float) $value : null,
            'unit' => 'POINT',
            'reason' => is_numeric($value) ? null : 'Belum ada nilai yang dapat dibandingkan.',
        ];
    }

    /**
     * Perubahan capaian akademik: membandingkan dua jendela waktu yang sama
     * panjangnya. Sengaja tidak memakai "nilai terakhir vs rata-rata", karena
     * perbandingan seperti itu sering menghasilkan penurunan palsu.
     *
     * @return array{value: float|null, unit: string|null, reason?: string}
     */
    private function academicChange(int $studentId, CarbonInterface $from, CarbonInterface $to): array
    {
        $days = max(1, $from->diffInDays($to));
        $previousTo = $from->copy()->subDay();
        $previousFrom = $previousTo->copy()->subDays($days);

        $current = $this->attendanceOnly($studentId, $from, $to);
        $previous = $this->attendanceOnly($studentId, $previousFrom, $previousTo);

        if ($current === null || $previous === null) {
            return ['value' => null, 'unit' => 'POINT', 'reason' => 'Perbandingan periode memerlukan dua jendela data.'];
        }

        return ['value' => round($current - $previous, 2), 'unit' => 'POINT'];
    }

    private function attendanceOnly(int $studentId, CarbonInterface $from, CarbonInterface $to): ?float
    {
        $summary = $this->attendance->summary($studentId, $from->toDateString(), $to->toDateString());
        $value = $summary['percentage'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /** @return array{value: float|null, unit: string|null, reason?: string} */
    private function referralCount(int $studentId, CarbonInterface $from, CarbonInterface $to): array
    {
        $count = BkReferral::query()
            ->where('student_id', $studentId)
            ->whereBetween('created_at', [$from, $to])
            ->count();

        return ['value' => (float) $count, 'unit' => 'COUNT'];
    }

    /** @return array{value: float|null, unit: string|null, reason?: string} */
    private function followUpFailures(int $studentId, CarbonInterface $from, CarbonInterface $to): array
    {
        $count = BkFollowUp::query()
            ->whereHas('case', fn ($query) => $query->where('student_id', $studentId))
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('status', [FollowUpStatus::MISSED->value, FollowUpStatus::CANCELLED->value])
            ->count();

        return ['value' => (float) $count, 'unit' => 'COUNT'];
    }

    /** @return array{value: float|null, unit: string|null, reason?: string} */
    private function clinicVisits(int $studentId): array
    {
        $summary = $this->clinic->summary($studentId);

        if ($summary === null) {
            return ['value' => null, 'unit' => 'COUNT', 'reason' => 'Modul Clinic/UKS tidak tersedia.'];
        }

        $value = $summary['visits_90d'] ?? null;

        return [
            'value' => is_numeric($value) ? (float) $value : null,
            'unit' => 'COUNT',
            'reason' => 'Angka kunjungan klinik disediakan modul Clinic/UKS sebagai ringkasan 90 hari.',
        ];
    }

    /** @return array{value: float|null, unit: string|null, reason?: string} */
    private function boardingAdjustments(int $studentId): array
    {
        $summary = $this->boarding->summary($studentId);

        if ($summary === null || ($summary['is_boarder'] ?? false) !== true) {
            return ['value' => null, 'unit' => 'COUNT', 'reason' => 'Siswa tidak berasrama.'];
        }

        return [
            'value' => null,
            'unit' => 'COUNT',
            'reason' => 'Modul Asrama belum menyediakan jumlah penyesuaian; gunakan aturan berbasis kehadiran/kedisiplinan.',
        ];
    }
}
