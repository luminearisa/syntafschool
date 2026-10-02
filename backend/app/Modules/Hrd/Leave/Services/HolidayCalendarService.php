<?php

namespace App\Modules\Hrd\Leave\Services;

use App\Models\User;
use App\Modules\Hrd\Leave\Models\HrHoliday;
use App\Modules\Hrd\Leave\Models\HrHolidayCalendar;
use Carbon\Carbon;

/**
 * Kalender hari libur berlapis: nasional, organisasi, sekolah, keagamaan, dan
 * khusus. Cuti memakai kalender ini, sehingga hari libur tidak pernah memotong
 * saldo cuti pegawai.
 */
class HolidayCalendarService
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, User $actor): HrHolidayCalendar
    {
        return HrHolidayCalendar::query()->create([
            'code' => strtoupper((string) ($attributes['code'] ?? 'CAL-'.now()->format('Y'))),
            'name' => $attributes['name'] ?? 'Kalender '.now()->year,
            'calendar_type' => strtoupper((string) ($attributes['calendar_type'] ?? 'ORGANIZATION')),
            'organization_id' => $attributes['organization_id'] ?? null,
            'year' => (int) ($attributes['year'] ?? now()->year),
            'is_default' => (bool) ($attributes['is_default'] ?? false),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function addHoliday(HrHolidayCalendar $calendar, array $attributes): HrHoliday
    {
        return HrHoliday::query()->updateOrCreate(
            ['calendar_id' => $calendar->getKey(), 'date' => $attributes['date']],
            [
                'name' => $attributes['name'] ?? 'Hari libur',
                'holiday_type' => strtoupper((string) ($attributes['holiday_type'] ?? 'CUSTOM')),
                'is_paid' => (bool) ($attributes['is_paid'] ?? true),
                'is_recurring' => (bool) ($attributes['is_recurring'] ?? false),
                'notes' => $attributes['notes'] ?? null,
            ],
        );
    }

    /**
     * Kalender yang berlaku untuk sebuah unit, termasuk kalender yayasan.
     *
     * @return list<int>
     */
    public function calendarIdsFor(?int $organizationId): array
    {
        return HrHolidayCalendar::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('organization_id')->orWhere('organization_id', $organizationId))
            ->pluck('id')
            ->all();
    }

    public function isHoliday(string $date, ?int $organizationId = null): bool
    {
        return HrHoliday::query()
            ->whereIn('calendar_id', $this->calendarIdsFor($organizationId))
            ->where('date', $date)
            ->exists();
    }

    /** @return list<array<string, mixed>> */
    public function inRange(string $from, string $to, ?int $organizationId = null): array
    {
        return HrHoliday::query()
            ->whereIn('calendar_id', $this->calendarIdsFor($organizationId))
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get()
            ->map(fn (HrHoliday $holiday) => [
                'date' => $holiday->date?->toDateString(),
                'name' => $holiday->name,
                'type' => $holiday->holiday_type,
                'is_paid' => (bool) $holiday->is_paid,
            ])
            ->all();
    }

    /**
     * Menghitung hari cuti efektif: hari kerja, di luar akhir pekan dan hari
     * libur kalender.
     *
     * @return array{days: float, detail: list<array<string, mixed>>}
     */
    public function effectiveDays(string $from, string $to, ?int $organizationId = null, bool $countWeekend = false): array
    {
        $detail = [];
        $days = 0.0;

        for ($date = Carbon::parse($from); $date->lessThanOrEqualTo(Carbon::parse($to)); $date->addDay()) {
            $isWeekend = $date->isWeekend();
            $isHoliday = $this->isHoliday($date->toDateString(), $organizationId);
            $counted = $countWeekend || (! $isWeekend && ! $isHoliday);

            $detail[] = [
                'date' => $date->toDateString(),
                'weekend' => $isWeekend,
                'holiday' => $isHoliday,
                'counted' => $counted,
            ];

            if ($counted) {
                $days += 1;
            }
        }

        return ['days' => $days, 'detail' => $detail];
    }
}
