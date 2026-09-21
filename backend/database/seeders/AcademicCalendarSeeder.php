<?php

namespace Database\Seeders;

use App\Models\AcademicEvent;
use App\Models\AcademicYear;
use App\Models\EducationLevel;
use App\Models\EventType;
use App\Models\Semester;
use App\Services\EffectiveDayGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A4 — contoh kalender akademik + hari efektif.
 *
 * Membuat event nyata (awal/akhir semester, PTS, PAS, libur, rapor, MPLS) untuk
 * seluruh semester pada tahun ajaran aktif, lalu mengisi `effective_days`
 * semester aktif memakai logika generator yang sama dengan tombol
 * "Hitung Hari Efektif". Idempoten: aman dijalankan berulang.
 */
class AcademicCalendarSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(EventTypeSeeder::class);

        $types = EventType::pluck('id', 'code');

        $year = AcademicYear::where('is_active', true)->first() ?? AcademicYear::first();

        if (! $year) {
            $this->command?->warn('Tahun ajaran belum tersedia — AcademicCalendarSeeder dilewati.');

            return;
        }

        $semesters = Semester::where('academic_year_id', $year->id)
            ->orderBy('start_date')
            ->get();

        if ($semesters->isEmpty()) {
            $this->command?->warn('Semester belum tersedia — AcademicCalendarSeeder dilewati.');

            return;
        }

        foreach ($semesters as $semester) {
            $this->seedSemesterEvents($semester, $year, $types);
        }

        // Isi hari efektif semester aktif memakai generator yang sama.
        $active = Semester::where('is_active', true)->first() ?? $semesters->first();
        $generator = app(EffectiveDayGenerator::class);
        $levelIds = EducationLevel::pluck('id')->all();

        if ($levelIds === []) {
            $generator->generate($active, null, true);

            return;
        }

        foreach ($levelIds as $levelId) {
            $generator->generate($active, $levelId, true);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<string, int>  $types
     */
    protected function seedSemesterEvents(Semester $semester, AcademicYear $year, $types): void
    {
        $start = $semester->start_date->copy();
        $end = $semester->end_date->copy();
        $span = max(1, $start->diffInDays($end));
        $mid = (int) round($span * 0.4);

        $plan = [
            ['AWAL_SEMESTER', 'Awal Semester '.$semester->name, $start->copy(), $start->copy(), false],
            ['MPLS', 'Masa Pengenalan Lingkungan Sekolah', $start->copy()->addDays(1), $start->copy()->addDays(3), false],
            ['RAPAT_GURU', 'Rapat Guru Awal Semester', $start->copy()->addDays(4), $start->copy()->addDays(4), false],
            ['UTS', 'Penilaian Tengah Semester', $start->copy()->addDays($mid), $start->copy()->addDays($mid + 4), false],
            ['UAS', 'Penilaian Akhir Semester', $end->copy()->subDays(20), $end->copy()->subDays(14), false],
            ['PEMBAGIAN_RAPOR', 'Pembagian Rapor', $end->copy()->subDays(8), $end->copy()->subDays(8), false],
            ['LIBUR_SEMESTER', 'Libur Semester', $end->copy()->subDays(6), $end->copy(), true],
            ['AKHIR_SEMESTER', 'Akhir Semester '.$semester->name, $end->copy(), $end->copy(), false],
        ];

        foreach ($plan as [$code, $title, $eventStart, $eventEnd, $isHoliday]) {
            if (! isset($types[$code])) {
                continue;
            }

            $this->storeEvent($semester, $year, (int) $types[$code], $title, $eventStart, $eventEnd, $isHoliday);
        }

        $this->seedNationalHolidays($semester, $year, $types);
    }

    /**
     * Libur nasional umum yang jatuh di dalam rentang semester.
     *
     * @param  \Illuminate\Support\Collection<string, int>  $types
     */
    protected function seedNationalHolidays(Semester $semester, AcademicYear $year, $types): void
    {
        if (! isset($types['LIBUR_NASIONAL'])) {
            return;
        }

        $start = $semester->start_date->copy();
        $end = $semester->end_date->copy();

        $nationals = [
            'Hari Kemerdekaan Republik Indonesia' => '08-17',
            'Hari Natal' => '12-25',
            'Tahun Baru Masehi' => '01-01',
            'Hari Buruh Internasional' => '05-01',
            'Hari Pendidikan Nasional' => '05-02',
        ];

        foreach ($nationals as $title => $monthDay) {
            [$month, $day] = array_map('intval', explode('-', $monthDay));

            $date = Carbon::create($start->year, $month, $day)->startOfDay();
            if ($date->lt($start)) {
                $date->addYear();
            }

            if (! $date->betweenIncluded($start, $end)) {
                continue;
            }

            // Jangan tumpang tindih dengan libur lain (mis. libur semester).
            $overlaps = AcademicEvent::query()
                ->holiday()
                ->where('semester_id', $semester->id)
                ->overlapping($date->toDateString(), $date->toDateString())
                ->exists();

            if ($overlaps) {
                continue;
            }

            $this->storeEvent(
                $semester,
                $year,
                (int) $types['LIBUR_NASIONAL'],
                $title,
                $date->copy(),
                $date->copy(),
                true,
            );
        }
    }

    protected function storeEvent(
        Semester $semester,
        AcademicYear $year,
        int $eventTypeId,
        string $title,
        Carbon $start,
        Carbon $end,
        bool $isHoliday,
    ): void {
        // Jaga rentang tetap di dalam semester.
        $semesterStart = $semester->start_date->copy();
        $semesterEnd = $semester->end_date->copy();

        if ($start->lt($semesterStart)) {
            $start = $semesterStart->copy();
        }
        if ($end->gt($semesterEnd)) {
            $end = $semesterEnd->copy();
        }
        if ($end->lt($start)) {
            $end = $start->copy();
        }

        AcademicEvent::updateOrCreate(
            ['semester_id' => $semester->id, 'title' => $title],
            [
                'academic_year_id' => $year->id,
                'event_type_id' => $eventTypeId,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'is_holiday' => $isHoliday,
                'is_active' => true,
            ],
        );
    }
}
