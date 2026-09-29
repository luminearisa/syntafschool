<?php

namespace App\Modules\LMS\Application\Services;

use App\Models\User;
use App\Modules\LMS\Application\Concerns\LogsLmsAudit;
use App\Modules\LMS\Domain\Enums\GamificationEventType;
use App\Modules\LMS\Models\LmsBadgeAward;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsGamificationPoint;
use App\Modules\LMS\Models\LmsGroup;
use App\Modules\LMS\Models\LmsLearningStreak;
use App\Modules\LMS\Models\LmsUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ledger poin/XP, badge sebagai capaian, dan rentetan belajar (§26–§30).
 *
 * Gamifikasi selalu opsional dan default NONAKTIF (§28): bila modul dimatikan
 * atau course tidak mengaktifkannya, `award()` mengembalikan null tanpa error
 * sehingga pemanggil tidak perlu tahu status modul.
 *
 * Anti-abuse (§72) memakai `dedupe_key` unik: satu peristiwa hanya menghasilkan
 * satu baris ledger, jadi pemanggilan API berulang atau job dobel tidak
 * menggandakan XP.
 */
class GamificationService
{
    use LogsLmsAudit;

    public function __construct(private readonly LearningEventService $events) {}

    // ------------------------------------------------------------------ status modul

    /** Gamifikasi aktif bila modul dinyalakan admin DAN course mengizinkannya. */
    public function enabled(?LmsCourse $course = null): bool
    {
        if (! (bool) config('lms.gamification.enabled', false)) {
            return false;
        }

        return $course === null || $course->gamificationEnabled();
    }

    public function leaderboardEnabled(LmsCourse $course): bool
    {
        return $this->enabled($course)
            && (bool) config('lms.gamification.leaderboard_enabled', false)
            && (bool) $course->leaderboard_enabled;
    }

    /**
     * Bobot poin per jenis peristiwa (§28) — admin boleh menimpanya lewat
     * pengaturan tanpa mengubah enum.
     *
     * @return list<array<string, mixed>>
     */
    public function weights(): array
    {
        return array_map(fn (GamificationEventType $type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'points' => $this->weight($type),
            'default_points' => $type->defaultPoints(),
        ], GamificationEventType::cases());
    }

    public function weight(GamificationEventType $type): int
    {
        $configured = config('lms.gamification.points.'.$type->value);

        return $configured === null ? $type->defaultPoints() : (int) $configured;
    }

    // ------------------------------------------------------------------ ledger poin

    /**
     * Catat poin satu peristiwa.
     *
     * @param  array<string, mixed>  $metadata
     * @return LmsGamificationPoint|null null bila modul nonaktif atau poin sudah pernah dicatat
     */
    public function award(
        GamificationEventType $type,
        int $userId,
        ?LmsCourse $course = null,
        ?string $dedupeKey = null,
        ?int $points = null,
        array $metadata = [],
        ?Model $reference = null,
        ?Carbon $earnedAt = null,
    ): ?LmsGamificationPoint {
        if ($userId <= 0 || ! $this->enabled($course)) {
            return null;
        }

        $points ??= $this->weight($type);

        if ($points === 0) {
            return null;
        }

        $key = $dedupeKey ?? $this->defaultDedupeKey($type, $userId, $reference);

        $point = LmsGamificationPoint::query()->firstOrCreate(
            ['dedupe_key' => $key],
            [
                'user_id' => $userId,
                'course_id' => $course?->id,
                'event_type' => $type,
                'points' => $points,
                'reference_type' => $reference === null ? null : Str::snake(class_basename($reference)),
                'reference_id' => $reference?->getKey(),
                'note' => $metadata['note'] ?? null,
                'metadata' => $metadata === [] ? null : $metadata,
                'earned_at' => $earnedAt ?? now(),
            ],
        );

        if (! $point->wasRecentlyCreated) {
            // Sudah pernah dicatat — XP tidak boleh bertambah dua kali (§72).
            return null;
        }

        if ($course !== null) {
            LmsEnrollment::query()
                ->where('course_id', $course->id)
                ->where('user_id', $userId)
                ->increment('xp_points', $points);
        }

        if ($type !== GamificationEventType::STREAK_BONUS) {
            $this->touchStreak($userId, $course, $point->earned_at);
        }

        return $point;
    }

    /** Batalkan poin (mis. nilai dibatalkan) lalu selaraskan cache enrollment. */
    public function revoke(
        GamificationEventType $type,
        int $userId,
        ?LmsCourse $course = null,
        ?string $dedupeKey = null,
        ?Model $reference = null,
    ): bool {
        $key = $dedupeKey ?? $this->defaultDedupeKey($type, $userId, $reference);

        $point = LmsGamificationPoint::query()->where('dedupe_key', $key)->first();

        if ($point === null) {
            return false;
        }

        $course?->refresh();

        $point->delete();

        if ($course !== null) {
            $enrollment = LmsEnrollment::query()
                ->where('course_id', $course->id)
                ->where('user_id', $userId)
                ->first();

            if ($enrollment !== null) {
                $this->syncEnrollmentXp($enrollment);
            }
        }

        return true;
    }

    public function totalFor(int $userId, ?int $courseId = null): int
    {
        return (int) LmsGamificationPoint::query()
            ->where('user_id', $userId)
            ->when($courseId !== null, fn ($query) => $query->where('course_id', $courseId))
            ->sum('points');
    }

    /**
     * Ringkasan XP, badge, dan rentetan seorang siswa (§28–§30).
     *
     * @return array<string, mixed>
     */
    public function summaryFor(int $userId, ?int $courseId = null): array
    {
        $points = LmsGamificationPoint::query()
            ->where('user_id', $userId)
            ->when($courseId !== null, fn ($query) => $query->where('course_id', $courseId))
            ->get();

        $breakdown = [];

        foreach ($points->groupBy(fn (LmsGamificationPoint $point) => $point->event_type->value) as $value => $rows) {
            $type = GamificationEventType::tryFrom((string) $value);

            $breakdown[] = [
                'value' => (string) $value,
                'label' => $type?->label() ?? (string) $value,
                'total' => (int) $rows->sum('points'),
                'count' => $rows->count(),
            ];
        }

        usort($breakdown, fn ($a, $b) => $b['total'] <=> $a['total']);

        $badges = $this->badgesFor($userId, $courseId);

        return [
            'enabled' => $this->enabled($courseId === null ? null : LmsCourse::query()->find($courseId)),
            'total_points' => (int) $points->sum('points'),
            'breakdown' => $breakdown,
            'badge_total' => count($badges),
            'badges' => array_slice($badges, 0, 6),
            'streak' => $this->streakFor($userId, $courseId),
        ];
    }

    /**
     * Riwayat poin terbaru (ledger tidak pernah diubah; hanya ditambah).
     *
     * @return list<array<string, mixed>>
     */
    public function recentFor(int $userId, ?int $courseId = null, int $limit = 10): array
    {
        return LmsGamificationPoint::query()
            ->where('user_id', $userId)
            ->when($courseId !== null, fn ($query) => $query->where('course_id', $courseId))
            ->orderByDesc('earned_at')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (LmsGamificationPoint $point) => [
                'id' => (int) $point->id,
                'event_type' => $point->event_type->value,
                'label' => $point->event_type->label(),
                'points' => (int) $point->points,
                'note' => $point->note,
                'earned_at' => $point->earned_at?->toIso8601String(),
                'course_id' => $point->course_id === null ? null : (int) $point->course_id,
            ])
            ->all();
    }

    /**
     * Badge yang sudah diperoleh siswa (§27).
     *
     * @return list<array<string, mixed>>
     */
    public function badgesFor(int $userId, ?int $courseId = null): array
    {
        return LmsBadgeAward::query()
            ->with('badge')
            ->where('user_id', $userId)
            ->when($courseId !== null, fn ($query) => $query->where('course_id', $courseId))
            ->orderByDesc('awarded_at')
            ->get()
            ->filter(fn (LmsBadgeAward $award) => $award->badge !== null)
            ->map(fn (LmsBadgeAward $award) => [
                'id' => (int) $award->id,
                'badge_id' => (int) $award->badge_id,
                'code' => $award->badge->code,
                'name' => $award->badge->name,
                'icon' => $award->badge->icon,
                'image_path' => $award->badge->image_path,
                'points' => (int) $award->badge->points,
                'source' => $award->source->value,
                'reason' => $award->reason,
                'course_id' => $award->course_id === null ? null : (int) $award->course_id,
                'awarded_at' => $award->awarded_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** Selaraskan cache `xp_points` pada enrollment dengan ledger (§28). */
    public function syncEnrollmentXp(LmsEnrollment $enrollment): int
    {
        $total = $this->totalFor((int) $enrollment->user_id, (int) $enrollment->course_id);

        $enrollment->forceFill(['xp_points' => $total])->save();

        return $total;
    }

    /** Selaraskan seluruh cache XP sebuah course (dipakai setelah impor/koreksi). */
    public function syncCourseXp(LmsCourse $course): int
    {
        $count = 0;

        $course->enrollments()->chunkById(100, function ($enrollments) use (&$count): void {
            foreach ($enrollments as $enrollment) {
                $this->syncEnrollmentXp($enrollment);
                $count++;
            }
        });

        return $count;
    }

    /**
     * Papan peringkat course (§29).
     *
     * Default NONAKTIF dan hanya menampilkan nama yang diizinkan pengaturan
     * privasi (`name` | `initials` | `anonymized`).
     *
     * @return array<string, mixed>
     */
    public function leaderboardForCourse(LmsCourse $course, int $limit = 0, ?int $viewerId = null): array
    {
        if (! $this->leaderboardEnabled($course)) {
            return $this->emptyLeaderboard('course', $course->id);
        }

        $rows = $this->pointsByUser((int) $course->id, $limit);

        return $this->leaderboardPayload($rows, 'course', (int) $course->id, $viewerId, $limit);
    }

    /** Papan peringkat per kelompok/kelas di dalam course (§29). */
    public function leaderboardForGroup(LmsGroup $group, int $limit = 0, ?int $viewerId = null): array
    {
        $course = $group->course;

        if ($course === null || ! $this->leaderboardEnabled($course)) {
            return $this->emptyLeaderboard('class', (int) $group->id);
        }

        $memberIds = DB::table('lms_group_members')
            ->where('group_id', $group->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($memberIds === []) {
            return $this->emptyLeaderboard('class', (int) $group->id);
        }

        $rows = $this->pointsByUser((int) $course->id, $limit, $memberIds);

        return $this->leaderboardPayload($rows, 'class', (int) $group->id, $viewerId, $limit);
    }

    // ------------------------------------------------------------------ rentetan belajar

    /**
     * Rentetan hari belajar (§30).
     *
     * Definisi "hari aktif" mengikuti konfigurasi (`activity_completed` default)
     * dan zona waktu yang dikonfigurasi — bukan zona waktu server.
     *
     * @return array<string, mixed>
     */
    public function streakFor(int $userId, ?int $courseId = null): array
    {
        $streak = LmsLearningStreak::query()
            ->where('user_id', $userId)
            ->when($courseId === null, fn ($query) => $query->whereNull('course_id'))
            ->when($courseId !== null, fn ($query) => $query->where('course_id', $courseId))
            ->first();

        $today = $this->streakDate(now());

        return [
            'enabled' => (bool) config('lms.gamification.streak.enabled', true) && $this->enabled(),
            'definition' => (string) config('lms.gamification.streak.definition', 'activity_completed'),
            'timezone' => $this->streakTimezone(),
            'current_streak' => (int) ($streak->current_streak ?? 0),
            'longest_streak' => (int) ($streak->longest_streak ?? 0),
            'total_active_days' => (int) ($streak->total_active_days ?? 0),
            'last_activity_date' => $streak?->last_activity_date?->toDateString(),
            'streak_started_on' => $streak?->streak_started_on?->toDateString(),
            'active_today' => $streak?->last_activity_date?->toDateString() === $today,
        ];
    }

    /**
     * Tandai satu hari aktif untuk siswa dan beri bonus tiap 7 hari (§30).
     *
     * Bila tanggal terakhir sama dengan hari ini, tidak ada yang berubah —
     * pemanggilan berulang tetap aman.
     */
    public function touchStreak(int $userId, ?LmsCourse $course = null, ?Carbon $at = null): ?LmsLearningStreak
    {
        if ($userId <= 0
            || ! $this->enabled($course)
            || ! (bool) config('lms.gamification.streak.enabled', true)) {
            return null;
        }

        $date = $this->streakDate($at ?? now());

        $streak = LmsLearningStreak::query()->firstOrNew([
            'user_id' => $userId,
            'course_id' => $course?->id,
        ]);

        $last = $streak->last_activity_date?->toDateString();

        if ($last === $date) {
            return $streak;
        }

        $consecutive = $last !== null && Carbon::parse($last)->addDay()->toDateString() === $date;
        $current = $consecutive ? (int) $streak->current_streak + 1 : 1;

        $streak->fill([
            'current_streak' => $current,
            'longest_streak' => max($current, (int) $streak->longest_streak),
            'last_activity_date' => $date,
            'streak_started_on' => $current === 1 ? $date : ($streak->streak_started_on ?? $date),
            'total_active_days' => (int) $streak->total_active_days + 1,
        ]);

        $streak->save();

        $bonus = (int) config('lms.gamification.streak.bonus_per_week', 5);

        if ($bonus > 0 && $current % 7 === 0) {
            $this->award(
                GamificationEventType::STREAK_BONUS,
                $userId,
                $course,
                'streak-bonus:'.$userId.':'.($course?->id ?? 'global').':'.$date,
                $bonus,
                ['note' => 'Bonus rentetan '.$current.' hari', 'streak' => $current],
            );
        }

        return $streak;
    }

    // ------------------------------------------------------------------ internal

    /** @return Collection<int, object> */
    private function pointsByUser(int $courseId, int $limit, ?array $onlyUserIds = null)
    {
        $limit = $limit > 0 ? $limit : (int) config('lms.gamification.leaderboard_limit', 20);

        return LmsGamificationPoint::query()
            ->where('course_id', $courseId)
            ->when($onlyUserIds !== null, fn ($query) => $query->whereIn('user_id', $onlyUserIds))
            ->selectRaw('user_id, SUM(points) as total')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<string, mixed>
     */
    private function leaderboardPayload($rows, string $scope, int $scopeId, ?int $viewerId, int $limit): array
    {
        $display = (string) config('lms.gamification.display', 'name');
        $names = $this->identityNames($rows->pluck('user_id')->map(fn ($id) => (int) $id)->all());

        $entries = $rows->values()->map(fn ($row, int $index) => [
            'rank' => $index + 1,
            'user_id' => (int) $row->user_id,
            'name' => $this->publicName($names[(int) $row->user_id] ?? null, $display, $index + 1),
            'points' => (int) $row->total,
            'is_me' => $viewerId !== null && (int) $row->user_id === $viewerId,
        ])->all();

        $me = null;

        if ($viewerId !== null) {
            $myPoints = $this->totalFor($viewerId, $scope === 'course' ? $scopeId : null);

            $me = [
                'rank' => $this->rankFor($viewerId, $scopeId, $myPoints),
                'user_id' => $viewerId,
                'name' => $this->publicName($names[$viewerId] ?? null, $display, 0),
                'points' => $myPoints,
                'is_me' => true,
            ];
        }

        return [
            'enabled' => true,
            'scope' => $scope,
            'scope_id' => $scopeId,
            'display' => $display,
            'limit' => max(1, $limit > 0 ? $limit : (int) config('lms.gamification.leaderboard_limit', 20)),
            'entries' => $entries,
            'me' => $me,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyLeaderboard(string $scope, int $scopeId): array
    {
        return [
            'enabled' => false,
            'scope' => $scope,
            'scope_id' => $scopeId,
            'display' => (string) config('lms.gamification.display', 'name'),
            'limit' => (int) config('lms.gamification.leaderboard_limit', 20),
            'entries' => [],
            'me' => null,
        ];
    }

    private function rankFor(int $userId, int $courseId, int $points): int
    {
        $better = LmsGamificationPoint::query()
            ->where('course_id', $courseId)
            ->where('user_id', '!=', $userId)
            ->selectRaw('user_id, SUM(points) as total')
            ->groupBy('user_id')
            ->havingRaw('SUM(points) > ?', [$points])
            ->get()
            ->count();

        return $better + 1;
    }

    /** @param list<int> $userIds @return array<int, string> */
    private function identityNames(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $model = config('lms.standalone', false) ? LmsUser::class : User::class;

        return $model::query()
            ->whereIn('id', $userIds)
            ->pluck('name', 'id')
            ->map(fn ($name) => (string) $name)
            ->all();
    }

    /** Nama tampilan mengikuti pengaturan privasi — bukan nama lengkap paksa. */
    private function publicName(?string $name, string $display, int $position): string
    {
        if ($name === null || $name === '') {
            return 'Peserta';
        }

        return match ($display) {
            'initials' => $this->initials($name),
            'anonymized' => 'Peserta '.($position > 0 ? '#'.$position : ''),
            default => $name,
        };
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        $initials = array_map(
            fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)).'.',
            array_slice(array_filter($parts), 0, 3),
        );

        return $initials === [] ? 'Peserta' : implode(' ', $initials);
    }

    private function streakTimezone(): string
    {
        return (string) config('lms.gamification.streak.timezone', config('app.timezone', 'Asia/Jakarta'));
    }

    private function streakDate(Carbon $at): string
    {
        return $at->copy()->setTimezone($this->streakTimezone())->toDateString();
    }

    private function defaultDedupeKey(GamificationEventType $type, int $userId, ?Model $reference): string
    {
        $reference = $reference === null
            ? ''
            : ':'.Str::snake(class_basename($reference)).':'.$reference->getKey();

        return strtolower($type->value).':'.$userId.$reference;
    }
}
