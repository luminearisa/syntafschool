<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perubahan jadwal pada satu tanggal tertentu.
 *
 * Jadwal pokok (`schedules`) tidak diubah; perubahan harian dicatat di sini
 * supaya jadwal asli tetap bisa diaudit. Tiga jenis perubahan:
 * - `pengganti` — guru berhalangan, digantikan guru lain.
 * - `pindah`     — jam/hari/ruang bergeser pada tanggal tersebut.
 * - `batal`      — kegiatan dibatalkan (mis. kelas kosong karena rapat).
 */
class ScheduleChange extends BaseModel
{
    protected $casts = [
        'date' => 'date',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function substituteEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'substitute_employee_id');
    }

    public function newRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'new_room_id');
    }

    public function newDay(): BelongsTo
    {
        return $this->belongsTo(Day::class, 'new_day_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
