<?php

namespace App\Modules\Asset\Infrastructure\Providers;

use App\Modules\Asset\Domain\Contracts\AcademicProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jembatan ke jadwal Akademik.
 *
 * Pemetaan ruang dilakukan lewat `locations.academic_room_id`: sekolah yang
 * ingin booking fasilitasnya menghormati jadwal pelajaran mengisi kolom itu, dan
 * pemeriksaan benturan pun berjalan. Tanpa pemetaan tersebut, penyedia ini
 * mengembalikan daftar kosong — bukan menebak-nebak ruang mana yang dimaksud.
 */
class EloquentAcademicProvider implements AcademicProviderInterface
{
    public function roomScheduleConflicts(int $locationId, string $from, string $to): array
    {
        $roomId = $this->academicRoomId($locationId);

        if ($roomId === null || ! Schema::hasTable('schedules')) {
            return [];
        }

        $date = \Illuminate\Support\Carbon::parse($from);
        $dayId = $this->dayId($date->dayOfWeek);

        $rows = DB::table('schedules')
            ->where('room_id', $roomId)
            ->when($dayId !== null, fn ($query) => $query->where('day_id', $dayId))
            ->where('start_time', '<', $date->copy()->parse($to)->format('H:i:s'))
            ->where('end_time', '>', $date->format('H:i:s'))
            ->get();

        return $rows->map(fn ($row): array => [
            'schedule_id' => $row->id,
            'label' => 'Kelas terjadwal '.substr((string) $row->start_time, 0, 5).'–'.substr((string) $row->end_time, 0, 5),
            'start_time' => $row->start_time,
            'end_time' => $row->end_time,
        ])->all();
    }

    public function roomSchedule(int $locationId, string $date): array
    {
        $roomId = $this->academicRoomId($locationId);

        if ($roomId === null || ! Schema::hasTable('schedules')) {
            return [];
        }

        $dayId = $this->dayId(\Illuminate\Support\Carbon::parse($date)->dayOfWeek);

        return DB::table('schedules')
            ->where('room_id', $roomId)
            ->when($dayId !== null, fn ($query) => $query->where('day_id', $dayId))
            ->orderBy('start_time')
            ->get()
            ->map(fn ($row): array => [
                'schedule_id' => $row->id,
                'start_time' => $row->start_time,
                'end_time' => $row->end_time,
                'subject_id' => $row->subject_id ?? null,
                'employee_id' => $row->employee_id ?? null,
            ])
            ->all();
    }

    protected function academicRoomId(int $locationId): ?int
    {
        if (! Schema::hasTable('locations') || ! Schema::hasColumn('locations', 'academic_room_id')) {
            return null;
        }

        $value = DB::table('locations')->where('id', $locationId)->value('academic_room_id');

        return $value === null ? null : (int) $value;
    }

    /**
     * Pemetaan hari Akademik.
     *
     * Tabel jadwal menyimpan hari sebagai id; banyak sekolah memakai 1=Senin.
     * Pergeseran ini dapat diatur (`asset.integration.academic_day_offset`)
     * sehingga tidak perlu menebak di dalam kode.
     */
    protected function dayId(int $carbonDayOfWeek): ?int
    {
        $offset = (int) config('asset.integration.academic_day_offset', -1);

        return $carbonDayOfWeek + $offset + 1;
    }
}
