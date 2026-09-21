<?php

namespace Database\Seeders;

use App\Models\ClassSubject;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Semester;
use App\Models\SchoolClass;
use App\Models\TimeSlot;
use Illuminate\Database\Seeder;

/**
 * Jadwal pelajaran contoh untuk seluruh kelas pada semester aktif.
 *
 * Dibangun dengan algoritma greedy: setiap (hari, jam) diisi mapel yang
 * gurunya **belum** mengajar pada jam itu, sehingga jadwal yang dihasilkan
 * bebas bentrok guru tanpa perlu memanggil validasi controller.
 */
class ScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $semester = Semester::query()->where('is_active', true)->first()
            ?? Semester::query()->first();

        if (! $semester) {
            $this->command?->warn('ScheduleSeeder dilewati: belum ada semester.');

            return;
        }

        $classes = SchoolClass::query()->orderBy('id')->get();

        if ($classes->isEmpty()) {
            $this->command?->warn('ScheduleSeeder dilewati: belum ada kelas.');

            return;
        }

        // Jam pelajaran bertipe "pelajaran" saja (istirahat/upacara dilewati).
        $slots = TimeSlot::query()
            ->whereIn('day_id', [1, 2, 3, 4, 5])
            ->where('type', 'pelajaran')
            ->orderBy('day_id')
            ->orderBy('slot_number')
            ->get()
            ->groupBy('day_id');

        if ($slots->isEmpty()) {
            $this->command?->warn('ScheduleSeeder dilewati: belum ada jam pelajaran.');

            return;
        }

        $rooms = Room::query()->orderBy('id')->pluck('id');

        if ($rooms->isEmpty()) {
            $this->command?->warn('ScheduleSeeder dilewati: belum ada ruangan.');

            return;
        }

        /** @var array<string, true> $teacherBusy kunci "hari:slot:guru" */
        $teacherBusy = [];

        foreach ($classes as $classIndex => $class) {
            $offerings = ClassSubject::query()
                ->with('subject')
                ->where('school_class_id', $class->id)
                ->get();

            if ($offerings->isEmpty()) {
                continue;
            }

            // Kuota JP per mapel, dipakai untuk memutar mapel secara adil.
            $queue = [];

            foreach ($offerings as $offering) {
                for ($i = 0; $i < max(1, (int) $offering->weekly_hours); $i++) {
                    $queue[] = $offering;
                }
            }

            $queueCursor = $classIndex; // tiap kelas mulai dari mapel berbeda

            foreach ($slots as $dayId => $daySlots) {
                foreach ($daySlots as $slot) {
                    $picked = null;

                    // Cari mapel berikutnya yang gurunya masih kosong pada jam ini.
                    for ($attempt = 0; $attempt < count($queue); $attempt++) {
                        $candidate = $queue[($queueCursor + $attempt) % count($queue)];
                        $key = "{$dayId}:{$slot->id}:{$candidate->employee_id}";

                        if (! isset($teacherBusy[$key])) {
                            $picked = $candidate;
                            $queueCursor = ($queueCursor + $attempt + 1) % count($queue);
                            $teacherBusy[$key] = true;

                            break;
                        }
                    }

                    if ($picked === null) {
                        continue; // semua guru sibuk di jam ini — biarkan kosong
                    }

                    Schedule::updateOrCreate(
                        [
                            'school_class_id' => $class->id,
                            'day_id' => $dayId,
                            'time_slot_id' => $slot->id,
                            'semester_id' => $semester->id,
                        ],
                        [
                            'subject_id' => $picked->subject_id,
                            'employee_id' => $picked->employee_id,
                            'room_id' => $rooms[$classIndex % $rooms->count()],
                            'start_time' => $slot->start_time,
                            'end_time' => $slot->end_time,
                        ],
                    );
                }
            }
        }
    }
}
