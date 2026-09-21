<?php

namespace Database\Seeders;

use App\Models\Day;
use App\Models\TimeSlot;
use Illuminate\Database\Seeder;

/**
 * Jam pelajaran default (Senin–Jumat).
 *
 * Idempoten: dijalankan berulang kali tidak menggandakan baris, hanya
 * memperbarui jam pada slot yang sudah ada.
 */
class TimeSlotSeeder extends Seeder
{
    public function run(): void
    {
        // Pastikan hari tersedia lebih dulu (tabel `days` diisi AcademicSeeder).
        if (Day::count() === 0) {
            $this->command?->warn('Tabel `days` kosong — jalankan AcademicSeeder lebih dulu. TimeSlotSeeder dilewati.');

            return;
        }

        $created = 0;
        $updated = 0;

        foreach (TimeSlot::defaultTemplate() as $dayId => $slots) {
            foreach ($slots as $slot) {
                $existing = TimeSlot::where('day_id', $dayId)
                    ->where('slot_number', $slot['slot_number'])
                    ->first();

                TimeSlot::updateOrCreate(
                    ['day_id' => $dayId, 'slot_number' => $slot['slot_number']],
                    [
                        'label' => $slot['label'],
                        'start_time' => $slot['start_time'].':00',
                        'end_time' => $slot['end_time'].':00',
                        'type' => $slot['type'],
                        'is_active' => true,
                    ],
                );

                $existing ? $updated++ : $created++;
            }
        }

        $this->command?->info("Jam pelajaran: {$created} dibuat, {$updated} diperbarui.");
    }
}
