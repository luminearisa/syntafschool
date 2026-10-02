<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Providers;

use App\Modules\SchoolOperations\Religious\Application\Services\{
    PrayerScheduleService, RamadanService, ReligiousActivityService
};
use App\Modules\SchoolOperations\Religious\Domain\Contracts\ReligiousLifeProviderInterface;
use App\Modules\SchoolOperations\Religious\Models\Mosque;
use App\Modules\SchoolOperations\Religious\Models\ReligiousDutyAssignment;

/**
 * Kontrak yang DISEDIAKAN modul Religious untuk modul lain (§76).
 * Dipakai modul Academic, Event, Facility, Library, dan LMS untuk membaca
 * jadwal shalat, kegiatan, dan hari keagamaan tanpa menyalin datanya.
 */
class ReligiousModuleProvider implements ReligiousLifeProviderInterface
{
    public function __construct(
        protected PrayerScheduleService $prayers,
        protected ReligiousActivityService $activities,
        protected RamadanService $ramadan,
    ) {
    }

    public function prayerTimesFor(string $date, ?int $mosqueId = null): array
    {
        $schedule = $this->prayers->forDate($date, $mosqueId);

        $duties = $mosqueId ? ReligiousDutyAssignment::query()
            ->whereDate('duty_date', $date)->where('mosque_id', $mosqueId)->get() : collect();

        return array_map(fn (array $prayer): array => array_merge($prayer, [
            'imam' => $duties->where('prayer_type', $prayer['prayer_type'])->where('duty_role', 'IMAM')->first()?->personnel_id,
            'muadzin' => $duties->where('prayer_type', $prayer['prayer_type'])->where('duty_role', 'MUADZIN')->first()?->personnel_id,
        ]), $schedule['prayers']);
    }

    public function nextPrayer(?int $mosqueId = null, ?string $at = null): ?array
    {
        $schedule = $this->prayers->forDate($at ? substr($at, 0, 10) : now()->toDateString(), $mosqueId);

        return $schedule['next'];
    }

    public function todayActivities(?int $mosqueId = null, ?string $date = null): array
    {
        return $this->activities->today($mosqueId, $date);
    }

    public function isRamadan(?string $date = null): bool
    {
        return $this->ramadan->coversDate($date ?? now()->toDateString());
    }

    public function mosquesFor(?int $campusId = null): array
    {
        return Mosque::query()->where('active', true)
            ->when($campusId, fn ($q) => $q->where('campus_id', $campusId))->get()
            ->map(fn (Mosque $m): array => [
                'id' => $m->getKey(), 'code' => $m->code, 'name' => $m->name,
                'campus_id' => $m->campus_id, 'capacity' => $m->capacity,
                'status' => $m->operational_status,
            ])->all();
    }

    public function available(): bool
    {
        return true;
    }
}
