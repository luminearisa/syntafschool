<?php

namespace App\Modules\Workflow\Infrastructure\Calendar;

use App\Modules\Workflow\Domain\Contracts\BusinessCalendarInterface;
use App\Modules\Workflow\Models\WorkflowCalendar;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Kalender kerja bawaan (§70).
 *
 * Menghitung "48 jam kerja" secara sungguhan: melewati akhir pekan, hari libur
 * nasional/sekolah, dan berhenti di luar jam kerja. Bila kalender tidak tersedia
 * (atau `respect_working_hours` dimatikan), perhitungan jatuh ke menit kalender
 * biasa — perilaku yang benar untuk SLA operasional seperti "respon 60 menit".
 *
 * Kalender dibaca dari `workflow_calendars` (+ `workflow_calendar_holidays`).
 * Yang di-cache adalah **array konfigurasi**, bukan model Eloquent: menyimpan
 * model ke cache lalu membacanya pada proses lain menghasilkan objek tak utuh
 * (`__PHP_Incomplete_Class`) — kesalahan yang pernah terjadi dan ditutup di sini.
 */
final class WorkingCalendar implements BusinessCalendarInterface
{
    public function addWorkingMinutes(CarbonInterface $from, int $minutes, ?string $calendarCode = null): CarbonInterface
    {
        $calendar = $this->calendarConfig($calendarCode);

        if ($calendar === null || ! $this->respectsWorkingHours()) {
            return $from->copy()->addMinutes($minutes);
        }

        $moment = $this->toTimezone($from, $calendar['timezone']);
        $remaining = max(0, $minutes);
        $guard = 0;

        while ($remaining > 0 && $guard < 4000) {
            $guard++;

            if (! $this->isWorkingDay($moment, $calendar)) {
                $moment = $moment->copy()->addDay()->startOfDay();

                continue;
            }

            $workStart = $moment->copy()->setTimeFromTimeString($calendar['work_start']);
            $workEnd = $moment->copy()->setTimeFromTimeString($calendar['work_end']);

            if ($moment->lessThan($workStart)) {
                $moment = $workStart;
            }

            if ($moment->greaterThanOrEqualTo($workEnd)) {
                $moment = $moment->copy()->addDay()->startOfDay();

                continue;
            }

            $availableToday = (int) $moment->diffInMinutes($workEnd);

            if ($availableToday >= $remaining) {
                return $moment->copy()->addMinutes($remaining);
            }

            $remaining -= $availableToday;
            $moment = $moment->copy()->addDay()->startOfDay();
        }

        return $moment->copy()->addMinutes($remaining);
    }

    public function isWorkingMoment(CarbonInterface $moment, ?string $calendarCode = null): bool
    {
        $calendar = $this->calendarConfig($calendarCode);

        if ($calendar === null || ! $this->respectsWorkingHours()) {
            return true;
        }

        $local = $this->toTimezone($moment, $calendar['timezone']);

        if (! $this->isWorkingDay($local, $calendar)) {
            return false;
        }

        $start = $local->copy()->setTimeFromTimeString($calendar['work_start']);
        $end = $local->copy()->setTimeFromTimeString($calendar['work_end']);

        return $local->greaterThanOrEqualTo($start) && $local->lessThan($end);
    }

    public function minutesBetween(CarbonInterface $start, CarbonInterface $end, ?string $calendarCode = null): int
    {
        $calendar = $this->calendarConfig($calendarCode);

        if ($calendar === null || ! $this->respectsWorkingHours()) {
            return (int) $start->diffInMinutes($end, false);
        }

        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        $cursor = $this->toTimezone($start, $calendar['timezone']);
        $target = $this->toTimezone($end, $calendar['timezone']);
        $total = 0;
        $guard = 0;

        while ($cursor->lessThan($target) && $guard < 4000) {
            $guard++;

            if (! $this->isWorkingDay($cursor, $calendar)) {
                $cursor = $cursor->copy()->addDay()->startOfDay();

                continue;
            }

            $workStart = $cursor->copy()->setTimeFromTimeString($calendar['work_start']);
            $workEnd = $cursor->copy()->setTimeFromTimeString($calendar['work_end']);
            $segmentEnd = $target->lessThan($workEnd) ? $target : $workEnd;

            if ($segmentEnd->greaterThan($workStart)) {
                $total += (int) $workStart->diffInMinutes($segmentEnd, false);
            }

            if ($target->lessThanOrEqualTo($workEnd)) {
                break;
            }

            $cursor = $cursor->copy()->addDay()->startOfDay();
        }

        return max(0, $total);
    }

    protected function respectsWorkingHours(): bool
    {
        return (bool) config('workflow.business_calendar.respect_working_hours', true);
    }

    /**
     * @param  array{id: int, timezone: string, work_days: list<int>, work_start: string, work_end: string}  $calendar
     */
    protected function isWorkingDay(CarbonInterface $moment, array $calendar): bool
    {
        $holiday = $this->holidays($calendar['id'])[$moment->toDateString()] ?? null;

        if ($holiday !== null) {
            return $holiday;
        }

        return in_array($moment->isoWeekday(), $calendar['work_days'], true);
    }

    protected function toTimezone(CarbonInterface $moment, string $timezone): CarbonInterface
    {
        return Carbon::parse($moment->toIso8601String())->setTimezone($timezone ?: config('app.timezone'));
    }

    /**
     * @return array<string, bool> tanggal => apakah hari kerja
     */
    protected function holidays(int $calendarId): array
    {
        return Cache::remember(
            'workflow.calendar.'.$calendarId.'.holidays',
            now()->addMinutes(10),
            fn () => WorkflowCalendar::query()
                ->whereKey($calendarId)
                ->first()
                ?->holidays()
                ->get()
                ->mapWithKeys(fn ($holiday) => [$holiday->date->toDateString() => (bool) $holiday->is_working_day])
                ->all() ?? [],
        );
    }

    /**
     * Konfigurasi kalender yang berlaku — di-cache sebagai array.
     *
     * @return array{id: int, code: string, timezone: string, work_days: list<int>, work_start: string, work_end: string}|null
     */
    protected function calendarConfig(?string $code): ?array
    {
        $code ??= (string) config('workflow.calendar', 'DEFAULT');

        return Cache::remember(
            'workflow.calendar.config.'.$code,
            now()->addMinutes(10),
            function () use ($code): ?array {
                $calendar = WorkflowCalendar::query()
                    ->where('is_active', true)
                    ->where('code', $code)
                    ->first()
                    ?? WorkflowCalendar::query()->where('is_active', true)->orderByDesc('is_default')->first();

                if ($calendar === null) {
                    return null;
                }

                return [
                    'id' => (int) $calendar->id,
                    'code' => (string) $calendar->code,
                    'timezone' => (string) ($calendar->timezone ?: config('app.timezone')),
                    'work_days' => $calendar->workDays(),
                    'work_start' => $calendar->workStart(),
                    'work_end' => $calendar->workEnd(),
                ];
            },
        );
    }
}
