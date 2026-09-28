<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Contracts\StudentProviderInterface;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseAnnouncement;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsLearningItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Kalender belajar (§25): tenggat, jadwal buka/tutup, awal & akhir course,
 * serta pengumuman — dikelompokkan Hari ini / Minggu ini / Mendatang.
 */
class LmsCalendarService
{
    public function __construct(private readonly StudentProviderInterface $students) {}

    /** @return array<string, mixed> */
    public function agenda(LmsActor $actor, ?string $from = null, ?string $until = null): array
    {
        $start = $from !== null ? Carbon::parse($from)->startOfDay() : Carbon::today();
        $end = $until !== null ? Carbon::parse($until)->endOfDay() : Carbon::today()->addDays(60);

        $courseIds = $this->visibleCourseIds($actor);

        if ($courseIds === []) {
            return ['today' => [], 'this_week' => [], 'upcoming' => [], 'range' => ['from' => $start->toDateString(), 'until' => $end->toDateString()]];
        }

        $events = [
            ...$this->courseBoundaries($courseIds, $start, $end),
            ...$this->itemWindows($courseIds, $actor, $start, $end),
            ...$this->announcements($courseIds, $start, $end),
        ];

        usort($events, fn (array $a, array $b) => strcmp($a['date'], $b['date']));

        $weekEnd = Carbon::today()->endOfWeek(CarbonInterface::SUNDAY);

        return [
            'range' => ['from' => $start->toDateString(), 'until' => $end->toDateString()],
            'today' => array_values(array_filter($events, fn (array $e) => Carbon::parse($e['date'])->isToday())),
            'this_week' => array_values(array_filter($events, fn (array $e) => Carbon::parse($e['date'])->isAfter(Carbon::today()) && Carbon::parse($e['date'])->lte($weekEnd))),
            'upcoming' => array_values(array_filter($events, fn (array $e) => Carbon::parse($e['date'])->gt($weekEnd))),
        ];
    }

    /** @return list<int> */
    protected function visibleCourseIds(LmsActor $actor): array
    {
        if ($actor->can('lms.manage')) {
            return LmsCourse::query()->pluck('id')->map('intval')->all();
        }

        if ($actor->isTeacher()) {
            return LmsCourse::query()
                ->where(fn ($q) => $q->where('created_by', $actor->id)
                    ->orWhere('teacher_id', $actor->employeeId)
                    ->orWhereHas('teachers', fn ($t) => $t->where('user_id', $actor->id)))
                ->pluck('id')->map('intval')->all();
        }

        if ($actor->isParent()) {
            $userIds = collect($this->students->childrenOf($actor))->pluck('userId')->filter()->all();

            return $userIds === []
                ? []
                : LmsEnrollment::query()->whereIn('user_id', $userIds)->pluck('course_id')->map('intval')->unique()->all();
        }

        return LmsEnrollment::query()
            ->where('user_id', $actor->id)
            ->pluck('course_id')
            ->map('intval')
            ->all();
    }

    /** @param list<int> $courseIds */
    protected function courseBoundaries(array $courseIds, Carbon $start, Carbon $end): array
    {
        $events = [];

        LmsCourse::query()->whereIn('id', $courseIds)->get(['id', 'name', 'start_date', 'end_date'])->each(function (LmsCourse $course) use (&$events, $start, $end): void {
            foreach ([['start', $course->start_date, 'Course dimulai'], ['end', $course->end_date, 'Course berakhir']] as [$type, $date, $label]) {
                if ($date === null || $date->lt($start) || $date->gt($end)) {
                    continue;
                }

                $events[] = [
                    'type' => 'course_'.$type,
                    'title' => $label.': '.$course->name,
                    'date' => $date->toDateString(),
                    'course_id' => (int) $course->id,
                    'course_name' => (string) $course->name,
                    'icon' => $type === 'start' ? 'play-circle' : 'flag',
                ];
            }
        });

        return $events;
    }

    /** @param list<int> $courseIds */
    protected function itemWindows(array $courseIds, LmsActor $actor, Carbon $start, Carbon $end): array
    {
        $completed = [];

        $enrollments = LmsEnrollment::query()->where('user_id', $actor->id)->with('completions:id,enrollment_id,learning_item_id')->get();
        foreach ($enrollments as $enrollment) {
            foreach ($enrollment->completions as $completion) {
                $completed[] = (int) $completion->learning_item_id;
            }
        }

        $events = [];

        LmsLearningItem::query()
            ->with('course:id,name')
            ->whereIn('course_id', $courseIds)
            ->whereNotNull('availability')
            ->get()
            ->each(function (LmsLearningItem $item) use (&$events, $completed, $start, $end): void {
                $rules = $item->availabilityRules();

                foreach ([['open', $rules['available_from'] ?? null], ['close', $rules['available_until'] ?? null]] as [$type, $date]) {
                    if ($date === null) {
                        continue;
                    }

                    $moment = Carbon::parse($date);

                    if ($moment->lt($start) || $moment->gt($end)) {
                        continue;
                    }

                    $events[] = [
                        'type' => 'item_'.$type,
                        'title' => ($type === 'open' ? 'Dibuka: ' : 'Ditutup: ').$item->title,
                        'date' => $moment->toDateString(),
                        'time' => $moment->format('H:i'),
                        'course_id' => (int) $item->course_id,
                        'course_name' => $item->course?->name,
                        'item_id' => (int) $item->id,
                        'completed' => in_array((int) $item->id, $completed, true),
                        'icon' => $type === 'open' ? 'unlock' : 'clock',
                    ];
                }
            });

        return $events;
    }

    /** @param list<int> $courseIds */
    protected function announcements(array $courseIds, Carbon $start, Carbon $end): array
    {
        return LmsCourseAnnouncement::query()
            ->with('course:id,name')
            ->whereIn('course_id', $courseIds)
            ->whereBetween('published_at', [$start, $end])
            ->orderByDesc('published_at')
            ->limit(30)
            ->get()
            ->map(fn (LmsCourseAnnouncement $announcement) => [
                'type' => 'announcement',
                'title' => (string) $announcement->title,
                'date' => $announcement->published_at?->toDateString(),
                'course_id' => (int) $announcement->course_id,
                'course_name' => $announcement->course?->name,
                'announcement_id' => (int) $announcement->id,
                'icon' => 'megaphone',
            ])
            ->all();
    }
}
