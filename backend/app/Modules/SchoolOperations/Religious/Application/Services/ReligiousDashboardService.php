<?php

namespace App\Modules\SchoolOperations\Religious\Application\Services;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\{BoardingGatewayInterface, ParentGatewayInterface, StudentGatewayInterface};
use App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext;
use App\Modules\SchoolOperations\Religious\Models\{
    Mosque, MosqueDisplaySetting, PrayerSchedule, ReligiousAnnouncement, ReligiousDutyAssignment
};

/**
 * Dashboard & mode layar (§65-§70, §89, §90, §93).
 *
 * Menyediakan: layar masjid digital, mode mobile, dashboard siswa, wali,
 * musyrif/asrama, dan dashboard presensi keagamaan — semuanya mengikuti
 * kebijakan privasi institusi (data wali hanya untuk anaknya sendiri, §14).
 */
class ReligiousDashboardService
{
    public function __construct(
        protected ReligiousContext $context,
        protected PrayerScheduleService $prayers,
        protected PrayerAttendanceService $attendance,
        protected ReligiousActivityService $activities,
        protected StudentGatewayInterface $students,
        protected ParentGatewayInterface $parents,
        protected BoardingGatewayInterface $boarding,
    ) {
    }

    /** Payload layar digital masjid (§65, §89). */
    public function display(int $mosqueId, ?string $date = null): array
    {
        $date ??= now()->toDateString();
        $mosque = Mosque::query()->find($mosqueId);

        $setting = MosqueDisplaySetting::query()->where('mosque_id', $mosqueId)
            ->where('active', true)->orderByDesc('id')->first();

        $schedule = $this->prayers->forDate($date, $mosqueId);
        $prayers = $schedule['prayers'];

        $duties = ReligiousDutyAssignment::query()->whereDate('duty_date', $date)
            ->where('mosque_id', $mosqueId)->get();

        $currentPrayer = null;
        foreach ($prayers as $prayer) {
            $adhan = $this->context->timeToMinutes($prayer['adhan_time'] ?? null);
            $end = $this->context->timeToMinutes($prayer['end_time'] ?? null);
            $nowMinutes = (int) now()->format('H') * 60 + (int) now()->format('i');
            if ($adhan !== null && $end !== null && $nowMinutes >= $adhan && $nowMinutes <= $end) {
                $currentPrayer = $prayer;
                break;
            }
        }

        $next = $schedule['next'];
        $iqamahCountdown = null;
        if ($next && ! empty($next['iqamah_time'])) {
            $diff = $this->context->minutesBetween(now()->format('H:i'), $next['iqamah_time']);
            $iqamahCountdown = $diff !== null && $diff >= 0 ? $diff : null;
        }

        return [
            'mosque' => $mosque ? ['id' => $mosque->getKey(), 'name' => $mosque->name, 'code' => $mosque->code] : null,
            'mode' => $setting?->display_mode ?? 'FULLSCREEN',
            'refresh_seconds' => (int) ($setting?->refresh_seconds ?? 30),
            'now' => now()->toIso8601String(),
            'date' => $date,
            'current_prayer' => $currentPrayer,
            'next_prayer' => $next,
            'iqamah_countdown_minutes' => $iqamahCountdown,
            'module_flags' => [
                'show_current_prayer' => (bool) ($setting?->show_current_prayer ?? true),
                'show_next_prayer' => (bool) ($setting?->show_next_prayer ?? true),
                'show_iqamah_countdown' => (bool) ($setting?->show_iqamah_countdown ?? true),
                'show_imam' => (bool) ($setting?->show_imam ?? true),
                'show_muadzin' => (bool) ($setting?->show_muadzin ?? true),
                'show_activities' => (bool) ($setting?->show_activities ?? true),
                'show_announcements' => (bool) ($setting?->show_announcements ?? true),
            ],
            'duty' => $duties->map(fn (ReligiousDutyAssignment $d): array => [
                'role' => $d->duty_role, 'prayer_type' => $d->prayer_type,
                'personnel_id' => $d->personnel_id, 'name' => $d->personnel_name,
            ])->all(),
            'activities' => $this->activities->today($mosqueId, $date),
            'announcements' => $this->announcements($mosqueId),
        ];
    }

    protected function announcements(int $mosqueId): array
    {
        return $this->context->safe(fn () => ReligiousAnnouncement::query()
            ->where('mosque_id', $mosqueId)->where('status', 'PUBLISHED')
            ->where(fn ($q) => $q->whereNull('publish_until')->orWhere('publish_until', '>=', now()))
            ->orderByDesc('published_at')->limit(5)->get()
            ->map(fn (ReligiousAnnouncement $a): array => [
                'title' => $a->title, 'body' => $a->body, 'priority' => $a->priority,
            ])->all(), []);
    }

    /** Mode mobile (§66): shalat hari ini, berikutnya, iqamah, kajian, Jumat. */
    public function mobile(?int $mosqueId = null, ?string $date = null): array
    {
        $date ??= now()->toDateString();
        $schedule = $this->prayers->forDate($date, $mosqueId);

        return [
            'date' => $date,
            'prayers' => $schedule['prayers'],
            'next_prayer' => $schedule['next'],
            'duty' => $mosqueId ? $this->context->safe(fn () => ReligiousDutyAssignment::query()
                ->whereDate('duty_date', $date)->where('mosque_id', $mosqueId)->get()
                ->map(fn (ReligiousDutyAssignment $d): array => [
                    'role' => $d->duty_role, 'prayer_type' => $d->prayer_type,
                ])->all(), []) : [],
            'activities' => $this->activities->today($mosqueId, $date),
            'friday' => app(FridayPrayerService::class)->upcoming(2, $mosqueId),
            'ramadan' => app(RamadanService::class)->dashboard($date),
        ];
    }

    /** Dashboard siswa (§67): kegiatan hari ini + status presensi sendiri. */
    public function student(int $studentId, ?string $date = null): array
    {
        $date ??= now()->toDateString();
        $snapshot = $this->students->snapshot($studentId);

        $records = $this->context->safe(fn () => \App\Modules\SchoolOperations\Religious\Models\PrayerAttendanceRecord::query()
            ->where('student_id', $studentId)->whereDate('attendance_date', $date)->get()
            ->map(fn ($r): array => ['prayer_type' => $r->prayer_type, 'status' => $r->status])
            ->all(), []);

        $participations = $this->context->safe(fn () => \App\Modules\SchoolOperations\Religious\Models\ReligiousActivityParticipant::query()
            ->where('student_id', $studentId)
            ->whereHas('activity', fn ($q) => $q->where('status', '!=', 'DRAFT'))
            ->limit(20)->get()
            ->map(fn ($p): array => ['activity_id' => $p->activity_id, 'status' => $p->status])
            ->all(), []);

        return [
            'student' => $snapshot ? ['id' => $snapshot['id'], 'full_name' => $snapshot['full_name'], 'class_name' => $snapshot['class_name'] ?? null] : null,
            'date' => $date,
            'prayer_attendance' => $records,
            'activities' => $participations,
            'boarding' => $this->boarding->isResident($studentId, $date),
            'quran_progress' => $this->context->safe(fn () => app(\App\Modules\SchoolOperations\Religious\Domain\Contracts\TahfidzGatewayInterface::class)->progressFor($studentId), ['available' => false]),
        ];
    }

    /**
     * Dashboard wali (§68). Data hanya untuk anak yang menjadi tanggung
     * jawabnya (§14) dan mengikuti kebijakan privasi institusi.
     */
    public function parent(int $parentUserId, ?string $date = null): array
    {
        $date ??= now()->toDateString();

        if (! (bool) config('religious.portal.parent_dashboard_enabled', true)) {
            return [
                'enabled' => false,
                'reason' => 'Dashboard keagamaan untuk wali tidak diaktifkan oleh institusi (§68).',
            ];
        }

        $children = $this->parents->childrenFor($parentUserId);
        $out = [];

        foreach ($children as $child) {
            $studentId = (int) ($child['id'] ?? 0);
            if (! $studentId || ! $this->parents->canAccess($parentUserId, $studentId)) {
                continue;
            }

            $out[] = [
                'student_id' => $studentId,
                'prayer_attendance' => $this->context->safe(fn () => \App\Modules\SchoolOperations\Religious\Models\PrayerAttendanceRecord::query()
                    ->where('student_id', $studentId)->whereDate('attendance_date', $date)->get()
                    ->map(fn ($r): array => ['prayer_type' => $r->prayer_type, 'status' => $r->status])
                    ->all(), []),
                'achievements' => $this->context->safe(fn () => \App\Modules\SchoolOperations\Religious\Models\ReligiousAchievement::query()
                    ->where('student_id', $studentId)->orderByDesc('achievement_date')->limit(5)->get()
                    ->map(fn ($a): array => ['title' => $a->title, 'date' => (string) $a->achievement_date])
                    ->all(), []),
            ];
        }

        return [
            'enabled' => true,
            'date' => $date,
            'children' => $out,
            'privacy_note' => 'Data ditampilkan terbatas pada anak yang menjadi tanggung jawab wali (§14).',
        ];
    }

    /** Dashboard musyrif/asrama (§69). */
    public function boarding(?int $dormitoryId, ?string $date = null): array
    {
        $date ??= now()->toDateString();
        $query = \App\Modules\SchoolOperations\Religious\Models\PrayerAttendanceRecord::query()
            ->whereDate('attendance_date', $date)
            ->when($dormitoryId, fn ($q) => $q->where('dormitory_id', $dormitoryId));

        $records = $query->get();

        return [
            'date' => $date,
            'dormitory_id' => $dormitoryId,
            'residents_expected' => $records->count(),
            'present' => $records->where('status', 'PRESENT')->count(),
            'late' => $records->where('status', 'LATE')->count(),
            'absent' => $records->where('status', 'ABSENT')->count(),
            'excused' => $records->where('status', 'EXCUSED')->count(),
            'by_prayer' => $records->groupBy('prayer_type')->map(fn ($g): array => [
                'present' => $g->where('status', 'PRESENT')->count(),
                'late' => $g->where('status', 'LATE')->count(),
                'absent' => $g->where('status', 'ABSENT')->count(),
                'excused' => $g->where('status', 'EXCUSED')->count(),
            ])->all(),
        ];
    }

    /** Dashboard presensi keagamaan (§70). */
    public function attendance(?string $date = null, array $filters = []): array
    {
        return $this->attendance->dashboard($date ?? now()->toDateString(), $filters);
    }

    /** Dashboard utama modul (§76). */
    public function overview(string $date): array
    {
        $mosques = Mosque::query()->where('active', true)->count();
        $stats = $this->attendance->statistics($date);

        return [
            'date' => $date,
            'mosques' => $mosques,
            'prayer_types' => count($this->prayers->prayerTypes()),
            'prayer_attendance' => [
                'total' => $stats['total'],
                'present' => $stats['present'],
                'late' => $stats['late'],
                'absent' => $stats['absent'],
                'excused' => $stats['excused'],
                'attendance_rate' => $stats['attendance_rate'],
            ],
            'by_prayer' => $stats['by_prayer'],
            'activities_today' => count($this->activities->today()),
            'friday_upcoming' => count(app(FridayPrayerService::class)->upcoming(3)),
            'ramadan' => app(RamadanService::class)->dashboard($date),
            'providers' => [
                'prayer_time' => $this->prayers->providerCapabilities(),
            ],
        ];
    }
}
