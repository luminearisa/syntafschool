<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Enums\LearningHealthStatus;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsActivityCompletion;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsLearningEvent;

/**
 * Indikator kesehatan belajar (§58–§61).
 *
 * Ini penanda AKTIVITAS LMS, bukan diagnosis atau penilaian kemampuan siswa:
 * hanya memakai data yang memang sudah ada (aktivitas terakhir, progress,
 * tenggat terlampaui, nilai). Ambangnya dapat diatur sekolah lewat konfigurasi.
 *
 * Status disimpan sebagai cache pada enrollment (`health_status`,
 * `health_computed_at`) supaya dasbor tidak menghitung ulang tiap permintaan.
 */
class LearningHealthService
{
    public function __construct(private readonly LearningEventService $events) {}

    /** @return list<array<string, mixed>> */
    public function statuses(): array
    {
        return array_map(fn (LearningHealthStatus $status) => [
            'value' => $status->value,
            'label' => $status->label(),
            'tone' => $status->tone(),
        ], LearningHealthStatus::cases());
    }

    /**
     * Hitung status satu enrollment tanpa menyimpan (murni baca).
     *
     * @param  array<string, mixed>|null  $metrics  hasil `metricsFor()` bila sudah ada
     */
    public function statusFor(LmsEnrollment $enrollment, ?array $metrics = null): LearningHealthStatus
    {
        $metrics ??= $this->metricsFor($enrollment);

        if ($metrics['completed']) {
            return LearningHealthStatus::COMPLETED;
        }

        if ($metrics['days_inactive'] !== null && $metrics['days_inactive'] >= $metrics['thresholds']['inactive_days']) {
            return LearningHealthStatus::INACTIVE;
        }

        if ($metrics['overdue_activities'] >= $metrics['thresholds']['at_risk_overdue']) {
            return LearningHealthStatus::AT_RISK;
        }

        if ($metrics['progress_percent'] < $metrics['thresholds']['low_completion_percent']) {
            return LearningHealthStatus::AT_RISK;
        }

        return LearningHealthStatus::ON_TRACK;
    }

    /** Hitung dan simpan status kesehatan satu enrollment. */
    public function computeForEnrollment(LmsEnrollment $enrollment): LearningHealthStatus
    {
        $status = $this->statusFor($enrollment);

        $enrollment->forceFill([
            'health_status' => $status,
            'health_computed_at' => now(),
        ])->save();

        return $status;
    }

    /** Hitung status seluruh peserta sebuah course; mengembalikan rekapnya. */
    public function computeForCourse(LmsCourse $course): array
    {
        $counts = [];

        foreach (LearningHealthStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        $course->enrollments()->where('role', 'STUDENT')->chunkById(100, function ($enrollments) use (&$counts): void {
            foreach ($enrollments as $enrollment) {
                $counts[$this->computeForEnrollment($enrollment)->value]++;
            }
        });

        return [
            'course_id' => (int) $course->id,
            'computed_at' => now()->toIso8601String(),
            'counts' => $counts,
            'total' => array_sum($counts),
        ];
    }

    /**
     * Ringkasan untuk dasbor guru: rekap status + peserta yang perlu perhatian.
     *
     * @return array<string, mixed>
     */
    public function summaryForCourse(LmsCourse $course, int $limit = 10): array
    {
        $enrollments = LmsEnrollment::query()
            ->where('course_id', $course->id)
            ->where('role', 'STUDENT')
            ->with('user')
            ->get();

        $counts = [];

        foreach (LearningHealthStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        $attention = [];

        foreach ($enrollments as $enrollment) {
            $metrics = $this->metricsFor($enrollment);
            $status = $this->statusFor($enrollment, $metrics);
            $counts[$status->value]++;

            if ($status === LearningHealthStatus::ON_TRACK || $status === LearningHealthStatus::COMPLETED) {
                continue;
            }

            $attention[] = [
                'enrollment_id' => (int) $enrollment->id,
                'user_id' => (int) $enrollment->user_id,
                'name' => $enrollment->user?->name,
                'status' => $status->value,
                'status_label' => $status->label(),
                'tone' => $status->tone(),
                'progress_percent' => $metrics['progress_percent'],
                'days_inactive' => $metrics['days_inactive'],
                'overdue_activities' => $metrics['overdue_activities'],
                'last_activity_at' => $metrics['last_activity_at'],
            ];
        }

        usort($attention, fn (array $a, array $b) => ($b['days_inactive'] ?? 0) <=> ($a['days_inactive'] ?? 0));

        return [
            'course_id' => (int) $course->id,
            'total_students' => $enrollments->count(),
            'counts' => $counts,
            'thresholds' => $this->thresholds(),
            'attention' => array_slice($attention, 0, max(1, $limit)),
            'attention_total' => count($attention),
        ];
    }

    /**
     * Tren aktivitas harian course (§58) — memakai satu tabel peristiwa yang
     * sudah ada, bukan penyimpanan analitik baru.
     *
     * @return array<string, mixed>
     */
    public function trendForCourse(LmsCourse $course, ?int $days = null): array
    {
        $days = $days ?? (int) config('lms.engagement.trend_days', 14);

        return [
            'course_id' => (int) $course->id,
            'days' => $days,
            'daily' => $this->events->dailyTrend($days, (int) $course->id),
            'active_learners' => [
                'week' => $this->events->activeLearners((int) $course->id, now()->subWeek()),
                'month' => $this->events->activeLearners((int) $course->id, now()->subMonth()),
            ],
            'event_counts' => $this->events->countsForCourse((int) $course->id, now()->subDays($days)),
        ];
    }

    /**
     * Metrik yang dipakai penilaian kesehatan.
     *
     * @return array<string, mixed>
     */
    public function metricsFor(LmsEnrollment $enrollment): array
    {
        $lastActivity = $enrollment->last_event_at
            ?? $this->events->lastActivityAt((int) $enrollment->user_id, (int) $enrollment->course_id);

        $completedItemIds = LmsActivityCompletion::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('status', 'COMPLETE')
            ->pluck('learning_item_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            'completed' => $enrollment->completed_at !== null || $enrollment->status->value === 'COMPLETED',
            'progress_percent' => (float) ($enrollment->progress_percent ?? 0),
            'last_activity_at' => $lastActivity?->toIso8601String(),
            'days_inactive' => $lastActivity === null
                ? ($enrollment->enrolled_at === null ? null : (int) $enrollment->enrolled_at->diffInDays(now()))
                : (int) $lastActivity->diffInDays(now()),
            'overdue_activities' => $this->overdueActivities((int) $enrollment->course_id, $completedItemIds),
            'thresholds' => $this->thresholds(),
        ];
    }

    /** @return array<string, mixed> */
    public function thresholds(): array
    {
        return [
            'inactive_days' => (int) config('lms.engagement.health.inactive_days', 14),
            'at_risk_overdue' => (int) config('lms.engagement.health.at_risk_overdue', 2),
            'low_completion_percent' => (float) config('lms.engagement.health.low_completion_percent', 40),
            'trend_days' => (int) config('lms.engagement.trend_days', 14),
        ];
    }

    /** Aktivitas terbit yang sudah melewati tenggat dan belum diselesaikan. */
    public function overdueActivities(int $courseId, array $completedItemIds = []): int
    {
        $query = LmsActivity::query()
            ->where('course_id', $courseId)
            ->whereNotNull('due_at')
            ->whereNotNull('published_at')
            ->where('due_at', '<', now())
            ->where('status', 'PUBLISHED');

        if ($completedItemIds !== []) {
            $query->whereNotIn('learning_item_id', $completedItemIds);
        }

        return $query->count();
    }

    /**
     * Peserta yang perlu perhatian pada beberapa course sekaligus (dasbor wali
     * kelas / admin §60–§61).
     *
     * @return list<array<string, mixed>>
     */
    public function atRiskAcrossCourses(int $userId, int $limit = 5): array
    {
        $rows = [];

        $enrollments = LmsEnrollment::query()
            ->where('user_id', $userId)
            ->where('role', 'STUDENT')
            ->with('course')
            ->get();

        foreach ($enrollments as $enrollment) {
            $metrics = $this->metricsFor($enrollment);
            $status = $this->statusFor($enrollment, $metrics);

            if ($status === LearningHealthStatus::ON_TRACK || $status === LearningHealthStatus::COMPLETED) {
                continue;
            }

            $rows[] = [
                'course_id' => (int) $enrollment->course_id,
                'course_name' => $enrollment->course?->name,
                'status' => $status->value,
                'status_label' => $status->label(),
                'tone' => $status->tone(),
                'progress_percent' => $metrics['progress_percent'],
                'days_inactive' => $metrics['days_inactive'],
                'overdue_activities' => $metrics['overdue_activities'],
            ];
        }

        usort($rows, fn (array $a, array $b) => ($b['days_inactive'] ?? 0) <=> ($a['days_inactive'] ?? 0));

        return array_slice($rows, 0, max(1, $limit));
    }

    /**
     * Angka untuk dasbor LMS pengajar: kelas yang perlu ditindaklanjuti.
     *
     * @return array<string, mixed>
     */
    public function dashboardForTeacher(LmsCourse $course): array
    {
        $summary = $this->summaryForCourse($course, 5);

        return [
            'course_id' => (int) $course->id,
            'counts' => $summary['counts'],
            'attention' => $summary['attention'],
            'attention_total' => $summary['attention_total'],
            'inactive_days_threshold' => $this->thresholds()['inactive_days'],
            'last_computed_at' => LmsEnrollment::query()
                ->where('course_id', $course->id)
                ->max('health_computed_at'),
        ];
    }

    /** Simpan cache status untuk seluruh course aktif (dipanggil scheduler). */
    public function refreshActiveCourses(): int
    {
        $count = 0;

        LmsCourse::query()
            ->where('status', 'PUBLISHED')
            ->chunkById(50, function ($courses) use (&$count): void {
                foreach ($courses as $course) {
                    $this->computeForCourse($course);
                    $count++;
                }
            });

        return $count;
    }

    /** Riwayat singkat peristiwa belajar terbaru seorang siswa (§58). */
    public function recentActivity(int $userId, ?int $courseId = null, int $limit = 10): array
    {
        return LmsLearningEvent::query()
            ->where('user_id', $userId)
            ->when($courseId !== null, fn ($query) => $query->where('course_id', $courseId))
            ->orderByDesc('occurred_at')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (LmsLearningEvent $event) => [
                'event_type' => $event->event_type->value,
                'label' => $event->event_type->label(),
                'course_id' => $event->course_id === null ? null : (int) $event->course_id,
                'item_id' => $event->item_id === null ? null : (int) $event->item_id,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'context' => $event->context,
            ])
            ->all();
    }
}
