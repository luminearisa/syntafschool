<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jam pelajaran pada satu hari.
 *
 * Contoh: hari Senin, jam ke-1, 07:00–07:45, bertipe `pelajaran`.
 * Jam istirahat & upacara juga tersimpan di sini agar grid jadwal punya
 * baris yang sejajar dengan kenyataan di sekolah.
 */
class TimeSlot extends BaseModel
{
    protected $casts = [
        'slot_number' => 'integer',
        'is_active' => 'boolean',
    ];

    public function day(): BelongsTo
    {
        return $this->belongsTo(Day::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    /** Hanya jam yang benar-benar dipakai untuk pelajaran. */
    public function scopeTeaching($query)
    {
        return $query->where('type', 'pelajaran');
    }

    /**
     * Template jam pelajaran default untuk sekolah menengah (Senin–Jumat).
     *
     * Senin–Kamis memakai jam pelajaran 45 menit dengan dua kali istirahat;
     * Jumat memakai 40 menit dan berakhir lebih awal. Template ini hanya titik
     * awal — sekolah tetap bisa menyunting jam pelajaran satu per satu.
     *
     * @return array<int, list<array{slot_number: int, label: string, start_time: string, end_time: string, type: string}>>
     */
    public static function defaultTemplate(): array
    {
        $reguler = [
            ['slot_number' => 1, 'label' => 'Jam ke-1', 'start_time' => '07:00', 'end_time' => '07:45', 'type' => 'pelajaran'],
            ['slot_number' => 2, 'label' => 'Jam ke-2', 'start_time' => '07:45', 'end_time' => '08:30', 'type' => 'pelajaran'],
            ['slot_number' => 3, 'label' => 'Jam ke-3', 'start_time' => '08:30', 'end_time' => '09:15', 'type' => 'pelajaran'],
            ['slot_number' => 4, 'label' => 'Istirahat', 'start_time' => '09:15', 'end_time' => '09:30', 'type' => 'istirahat'],
            ['slot_number' => 5, 'label' => 'Jam ke-4', 'start_time' => '09:30', 'end_time' => '10:15', 'type' => 'pelajaran'],
            ['slot_number' => 6, 'label' => 'Jam ke-5', 'start_time' => '10:15', 'end_time' => '11:00', 'type' => 'pelajaran'],
            ['slot_number' => 7, 'label' => 'Istirahat', 'start_time' => '11:00', 'end_time' => '11:15', 'type' => 'istirahat'],
            ['slot_number' => 8, 'label' => 'Jam ke-6', 'start_time' => '11:15', 'end_time' => '12:00', 'type' => 'pelajaran'],
            ['slot_number' => 9, 'label' => 'Jam ke-7', 'start_time' => '12:00', 'end_time' => '12:45', 'type' => 'pelajaran'],
            ['slot_number' => 10, 'label' => 'Jam ke-8', 'start_time' => '12:45', 'end_time' => '13:30', 'type' => 'pelajaran'],
        ];

        $jumat = [
            ['slot_number' => 1, 'label' => 'Jam ke-1', 'start_time' => '07:00', 'end_time' => '07:40', 'type' => 'pelajaran'],
            ['slot_number' => 2, 'label' => 'Jam ke-2', 'start_time' => '07:40', 'end_time' => '08:20', 'type' => 'pelajaran'],
            ['slot_number' => 3, 'label' => 'Jam ke-3', 'start_time' => '08:20', 'end_time' => '09:00', 'type' => 'pelajaran'],
            ['slot_number' => 4, 'label' => 'Istirahat', 'start_time' => '09:00', 'end_time' => '09:20', 'type' => 'istirahat'],
            ['slot_number' => 5, 'label' => 'Jam ke-4', 'start_time' => '09:20', 'end_time' => '10:00', 'type' => 'pelajaran'],
            ['slot_number' => 6, 'label' => 'Jam ke-5', 'start_time' => '10:00', 'end_time' => '10:40', 'type' => 'pelajaran'],
            ['slot_number' => 7, 'label' => 'Jam ke-6', 'start_time' => '10:40', 'end_time' => '11:20', 'type' => 'pelajaran'],
        ];

        return [
            1 => $reguler, // Senin
            2 => $reguler, // Selasa
            3 => $reguler, // Rabu
            4 => $reguler, // Kamis
            5 => $jumat,   // Jumat
        ];
    }
}
