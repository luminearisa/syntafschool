<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Enums\LearningEventType;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsInteractiveContent;
use App\Modules\LMS\Models\LmsLearningEvent;
use App\Modules\LMS\Models\LmsLearningItem;
use App\Modules\LMS\Models\LmsLiveSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Peristiwa belajar ringan (§38) sekaligus jejak audit aksi LMS (§36, §72).
 *
 * Sengaja tidak memakai observer global: hanya pemanggil yang tahu makna
 * sebuah peristiwa yang boleh mencatatnya. Penyimpanan cukup satu tabel
 * (`lms_learning_events`) dengan agregasi sederhana — bukan platform analitik.
 * Karena seluruh pemanggil lewat service ini, penyimpanan dapat diganti
 * (misal ke warehouse) tanpa mengubah domain.
 */
class LearningEventService
{
    /**
     * Catat satu peristiwa belajar.
     *
     * Aktor opsional: peristiwa anonim (mis. kunjungan publik) tetap dicatat
     * tanpa identitas agar tidak ada data pribadi yang tersimpan sia-sia.
     *
     * @param  array<string, mixed>  $context
     */
    public function record(
        LearningEventType $type,
        ?LmsActor $actor = null,
        ?LmsCourse $course = null,
        array $context = [],
        ?LmsLearningItem $item = null,
        ?LmsActivity $activity = null,
        ?LmsInteractiveContent $interactive = null,
        ?LmsLiveSession $session = null,
    ): ?LmsLearningEvent {
        try {
            $event = LmsLearningEvent::query()->create([
                'user_id' => $actor?->id,
                'course_id' => $course?->id,
                'section_id' => $item?->section_id ?? $activity?->section_id ?? $session?->section_id,
                'item_id' => $item?->id ?? $activity?->learning_item_id,
                'activity_id' => $activity?->id,
                'interactive_content_id' => $interactive?->id,
                'live_session_id' => $session?->id,
                'event_type' => $type->value,
                'actor_role' => $actor?->primaryRole->value,
                'context' => $context === [] ? null : $context,
                'occurred_at' => now(),
            ]);

            if ($actor !== null && $course !== null) {
                // Penanda aktivitas terakhir untuk indikator kesehatan belajar
                // (§60) — satu kolom cache, bukan tabel tandingan.
                LmsEnrollment::query()
                    ->where('course_id', $course->id)
                    ->where('user_id', $actor->id)
                    ->update(['last_event_at' => now()]);
            }

            return $event;
        } catch (\Throwable $e) {
            // Peristiwa engagement tidak boleh menggagalkan aksi belajar.
            report($e);

            return null;
        }
    }

    /**
     * Jejak audit aksi LMS. `lms` adalah nama log yang sudah dipakai modul lain,
     * sehingga riwayat tetap terpusat di satu tempat.
     *
     * @param  Model|string|null  $subject
     * @param  array<string, mixed>  $properties
     */
    public function audit(Model|string|null $subject, string $action, array $properties = [], ?LmsActor $actor = null): void
    {
        try {
            $logger = activity('lms')->event($action)->withProperties($properties);

            if ($subject instanceof Model) {
                $logger->performedOn($subject);
            } elseif (is_string($subject) && $subject !== '') {
                $logger->withProperties($properties + ['subject' => $subject]);
            }

            $actorId = $actor?->id ?? auth()->id();

            if ($actorId !== null) {
                $logger->causedBy($actorId);
            } else {
                $logger->causedByAnonymous();
            }

            $logger->log($action);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /* ---------------------------------------------------------------------
     | Agregasi ringan untuk guru, admin, dan indikator kesehatan
     * ------------------------------------------------------------------- */

    /** @return array<string, int> jumlah peristiwa per jenis */
    public function countsForCourse(int $courseId, ?Carbon $from = null, ?Carbon $until = null): array
    {
        $query = LmsLearningEvent::query()->where('course_id', $courseId);

        if ($from !== null) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($until !== null) {
            $query->where('occurred_at', '<=', $until);
        }

        return $query->selectRaw('event_type, COUNT(*) as total')
            ->groupBy('event_type')
            ->pluck('total', 'event_type')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /**
     * Deret harian peristiwa (atau satu jenis) untuk grafik 14 hari terakhir.
     *
     * @return list<array{date: string, total: int}>
     */
    public function dailyTrend(int $days = 14, ?int $courseId = null, ?LearningEventType $type = null): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $query = LmsLearningEvent::query()->where('occurred_at', '>=', $from);

        if ($courseId !== null) {
            $query->where('course_id', $courseId);
        }

        if ($type !== null) {
            $query->where('event_type', $type->value);
        }

        $rows = $query->selectRaw('DATE(occurred_at) as d, COUNT(*) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $from->copy()->addDays($i)->toDateString();
            $series[] = ['date' => $date, 'total' => (int) ($rows[$date] ?? 0)];
        }

        return $series;
    }

    /** Pembelajar aktif = punya minimal satu peristiwa belajar pada rentang itu. */
    public function activeLearners(int $courseId, ?Carbon $from = null, ?Carbon $until = null): int
    {
        $query = LmsLearningEvent::query()->where('course_id', $courseId)->whereNotNull('user_id');

        if ($from !== null) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($until !== null) {
            $query->where('occurred_at', '<=', $until);
        }

        return (int) $query->distinct()->count('user_id');
    }

    public function lastActivityAt(int $userId, ?int $courseId = null): ?Carbon
    {
        $query = LmsLearningEvent::query()->where('user_id', $userId);

        if ($courseId !== null) {
            $query->where('course_id', $courseId);
        }

        $value = $query->max('occurred_at');

        return $value !== null ? Carbon::parse($value) : null;
    }

    /**
     * Peristiwa terakhir tiap peserta pada sebuah course (untuk daftar "perlu
     * perhatian" guru §61) — satu query, bukan N+1.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, Carbon>
     */
    public function lastActivityForUsers(array $userIds, ?int $courseId = null): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        $query = LmsLearningEvent::query()->whereIn('user_id', $userIds);

        if ($courseId !== null) {
            $query->where('course_id', $courseId);
        }

        return $query->selectRaw('user_id, MAX(occurred_at) as last_at')
            ->groupBy('user_id')
            ->pluck('last_at', 'user_id')
            ->map(fn ($value) => Carbon::parse($value));
    }

    /**
     * Ringkas peristiwa terbaru untuk umpan aktivitas (§66).
     *
     * @return list<array<string, mixed>>
     */
    public function recentForCourse(int $courseId, int $limit = 10): array
    {
        return LmsLearningEvent::query()
            ->with(['item:id,title', 'activity:id,title'])
            ->where('course_id', $courseId)
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get()
            ->map(fn (LmsLearningEvent $event) => [
                'id' => (int) $event->id,
                'type' => $event->event_type?->value,
                'label' => $event->event_type?->label(),
                'user_id' => $event->user_id !== null ? (int) $event->user_id : null,
                'item' => $event->item?->title,
                'activity' => $event->activity?->title,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])
            ->all();
    }
}
