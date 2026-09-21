<?php

namespace Database\Seeders;

use App\Models\ClassSubject;
use App\Models\Schedule;
use App\Models\Semester;
use App\Models\TeachingJournal;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A11a — contoh jurnal mengajar ~2 pekan terakhir semester aktif.
 *
 * Jurnal dibuat dari `schedules` yang benar-benar ada: setiap jadwal yang jatuh
 * pada hari tertentu menghasilkan satu jurnal pada tanggal itu. Sebagian
 * ditandai `completed` (dengan realisasi JP), sebagian masih `planned`.
 * Idempoten: kunci alaminya (guru, tanggal, jam pelajaran).
 */
class TeachingJournalSeeder extends Seeder
{
    public function run(): void
    {
        $semester = Semester::where('is_active', true)->first() ?? Semester::first();

        if (! $semester) {
            $this->command?->warn('Semester belum tersedia — TeachingJournalSeeder dilewati.');

            return;
        }

        $schedules = Schedule::query()
            ->with(['subject', 'schoolClass', 'timeSlot'])
            ->whereNotNull('employee_id')
            ->whereNotNull('time_slot_id')
            ->where('semester_id', $semester->id)
            ->orderBy('day_id')
            ->orderBy('start_time')
            ->get();

        if ($schedules->isEmpty()) {
            $this->command?->warn('Jadwal pada semester aktif belum tersedia — TeachingJournalSeeder dilewati.');

            return;
        }

        $createdBy = User::query()->orderBy('id')->value('id');

        // Rentang: 14 hari terakhir, dipangkas agar tetap di dalam semester.
        $end = Carbon::today();
        $semesterEnd = Carbon::parse($semester->end_date);

        if ($semesterEnd->lt($end)) {
            $end = $semesterEnd->copy();
        }

        $start = $end->copy()->subDays(13);
        $semesterStart = Carbon::parse($semester->start_date);

        if ($start->lt($semesterStart)) {
            $start = $semesterStart->copy();
        }

        $processed = 0;
        $created = 0;
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $dayId = $cursor->dayOfWeekIso;

            foreach ($schedules->where('day_id', $dayId) as $schedule) {
                $processed++;

                $exists = TeachingJournal::query()
                    ->where('teacher_id', $schedule->employee_id)
                    ->whereDate('date', $cursor->toDateString())
                    ->where('time_slot_id', $schedule->time_slot_id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $allocation = $this->allocationFor(
                    (int) $schedule->school_class_id,
                    (int) $schedule->subject_id,
                );

                // Hari genap diselesaikan, hari ganjil masih direncanakan.
                $completed = $cursor->day % 2 === 0;
                $realizedJp = $completed ? max(1, min(2, $allocation ?? 1)) : 0;

                TeachingJournal::create([
                    'schedule_id' => $schedule->id,
                    'class_id' => $schedule->school_class_id,
                    'subject_id' => $schedule->subject_id,
                    'teacher_id' => $schedule->employee_id,
                    'semester_id' => $semester->id,
                    'time_slot_id' => $schedule->time_slot_id,
                    'date' => $cursor->toDateString(),
                    'topic' => $schedule->subject?->name
                        ? 'Pembahasan '.$schedule->subject->name
                        : 'Pembelajaran terjadwal',
                    'material' => 'Materi lanjutan sesuai silabus semester ini.',
                    'activities' => $completed
                        ? 'Pembuka, diskusi materi, latihan mandiri, dan refleksi.'
                        : null,
                    'notes' => null,
                    'student_present_count' => $completed ? 30 : 0,
                    'realized_jp' => $realizedJp,
                    'status' => $completed
                        ? TeachingJournal::STATUS_COMPLETED
                        : TeachingJournal::STATUS_PLANNED,
                    'created_by' => $createdBy,
                ]);

                $created++;
            }

            $cursor->addDay();
        }

        $this->command?->info(
            "Jurnal mengajar: {$processed} slot jadwal diperiksa, {$created} jurnal baru dibuat."
        );
    }

    /**
     * Alokasi JP mapel di kelas: `class_subjects.weekly_hours`, jatuh ke kredit mapel.
     */
    protected function allocationFor(int $classId, int $subjectId): ?int
    {
        $weekly = ClassSubject::query()
            ->where('school_class_id', $classId)
            ->where('subject_id', $subjectId)
            ->value('weekly_hours');

        if ($weekly !== null) {
            return (int) $weekly;
        }

        $credit = ClassSubject::query()
            ->where('school_class_id', $classId)
            ->where('subject_id', $subjectId)
            ->with('subject')
            ->first()?->subject?->credit;

        return $credit !== null ? (int) round((float) $credit) : null;
    }
}
