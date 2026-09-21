<?php

namespace App\Services;

use App\Models\AcademicEvent;
use App\Models\EffectiveDay;
use App\Models\Semester;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generator hari efektif per tanggal.
 *
 * Aturan:
 *  - Hari sekolah = Senin–Sabtu. Minggu bukan hari sekolah.
 *  - Seluruh tanggal yang tercakup event `is_holiday` pada semester itu
 *    dikurangi. Bila `academic_events` punya kolom `education_level_id`,
 *    event dengan jenjang tertentu hanya mengurangi jenjang tersebut; event
 *    tanpa jenjang mengurangi semua jenjang. Selama kolom itu belum ada,
 *    libur berlaku global.
 *  - `overwrite = false` melewati tanggal yang sudah ada; `true` memperbarui.
 *
 * Dipakai bersama oleh `AcademicCalendarController` dan `AcademicCalendarSeeder`
 * supaya hasil seeder identik dengan hasil tombol "Hitung Hari Efektif".
 */
class EffectiveDayGenerator
{
    /**
     * @return array{created: int, updated: int, skipped: int, school_days: int, holidays: int}
     */
    public function generate(Semester $semester, ?int $educationLevelId = null, bool $overwrite = false): array
    {
        $holidayDates = $this->holidayDates($semester, $educationLevelId);

        $start = $semester->start_date->copy()->startOfDay();
        $end = $semester->end_date->copy()->startOfDay();

        $existing = EffectiveDay::query()
            ->where('semester_id', $semester->id)
            ->when(
                $educationLevelId === null,
                fn ($q) => $q->whereNull('education_level_id'),
                fn ($q) => $q->where('education_level_id', $educationLevelId),
            )
            ->get()
            ->keyBy(fn (EffectiveDay $day) => $day->date->toDateString());

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $schoolDays = 0;
        $holidays = 0;

        DB::transaction(function () use (
            $semester,
            $educationLevelId,
            $overwrite,
            $start,
            $end,
            $holidayDates,
            $existing,
            &$created,
            &$updated,
            &$skipped,
            &$schoolDays,
            &$holidays,
        ) {
            for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                $key = $date->toDateString();
                $isSchoolDay = ! $date->isSunday() && ! isset($holidayDates[$key]);

                if ($isSchoolDay) {
                    $schoolDays++;
                } else {
                    $holidays++;
                }

                /** @var EffectiveDay|null $row */
                $row = $existing->get($key);

                if ($row) {
                    if (! $overwrite) {
                        $skipped++;
                        continue;
                    }

                    if ((bool) $row->is_school_day !== $isSchoolDay) {
                        $row->update(['is_school_day' => $isSchoolDay]);
                    }

                    $updated++;
                    continue;
                }

                EffectiveDay::create([
                    'semester_id' => $semester->id,
                    'education_level_id' => $educationLevelId,
                    'date' => $key,
                    'is_school_day' => $isSchoolDay,
                ]);

                $created++;
            }
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'school_days' => $schoolDays,
            'holidays' => $holidays,
        ];
    }

    /**
     * Kumpulan tanggal (Y-m-d) yang ditiadakan event libur pada semester ini.
     *
     * @return array<string, true>
     */
    protected function holidayDates(Semester $semester, ?int $educationLevelId): array
    {
        $query = AcademicEvent::query()
            ->holiday()
            ->where('semester_id', $semester->id)
            ->where('is_active', true);

        // Bila suatu saat event punya jenjang sendiri, hormati pemisahan itu.
        if (Schema::hasColumn('academic_events', 'education_level_id')) {
            $query->where(function ($q) use ($educationLevelId) {
                $q->whereNull('education_level_id');

                if ($educationLevelId !== null) {
                    $q->orWhere('education_level_id', $educationLevelId);
                }
            });
        }

        $dates = [];

        foreach ($query->get(['start_date', 'end_date']) as $event) {
            for ($day = $event->start_date->copy(); $day->lte($event->end_date); $day->addDay()) {
                $dates[$day->toDateString()] = true;
            }
        }

        return $dates;
    }
}
