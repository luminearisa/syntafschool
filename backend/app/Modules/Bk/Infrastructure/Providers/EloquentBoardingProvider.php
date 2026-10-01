<?php

namespace App\Modules\Bk\Infrastructure\Providers;

use App\Modules\Bk\Domain\Contracts\BoardingProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * Ringkasan asrama & komitmen waktu santri.
 *
 * Komitmen waktu diambil dari kegiatan asrama rutin (`boarding_activity_schedules`)
 * supaya konselor tidak menjadwalkan sesi tepat saat santri wajib mengaji atau
 * kegiatan malam.
 */
class EloquentBoardingProvider implements BoardingProviderInterface
{
    public function summary(int $studentId): ?array
    {
        $placement = DB::table('boarding_placements')
            ->leftJoin('boarding_dormitories', 'boarding_dormitories.id', '=', 'boarding_placements.dormitory_id')
            ->leftJoin('boarding_rooms', 'boarding_rooms.id', '=', 'boarding_placements.room_id')
            ->where('boarding_placements.student_id', $studentId)
            ->orderByDesc('boarding_placements.id')
            ->first([
                'boarding_dormitories.name as dormitory',
                'boarding_rooms.name as room',
                'boarding_placements.status as status',
            ]);

        if ($placement === null) {
            return ['dormitory' => null, 'room' => null, 'status' => null, 'is_boarder' => false];
        }

        return [
            'dormitory' => $placement->dormitory,
            'room' => $placement->room,
            'status' => $placement->status,
            'is_boarder' => true,
        ];
    }

    public function commitments(int $studentId, string $date): array
    {
        if (! DB::getSchemaBuilder()->hasTable('boarding_activity_schedules')) {
            return [];
        }

        $dayOfWeek = (int) date('N', strtotime($date));

        $dormitoryIds = DB::table('boarding_placements')
            ->where('student_id', $studentId)
            ->where('status', 'ACTIVE')
            ->pluck('dormitory_id');

        if ($dormitoryIds->isEmpty()) {
            return [];
        }

        return DB::table('boarding_activity_schedules')
            ->whereIn('dormitory_id', $dormitoryIds)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->get(['start_time', 'end_time', 'notes'])
            ->map(fn ($row): array => [
                'label' => $row->notes ?: 'Kegiatan asrama',
                'start' => substr((string) $row->start_time, 0, 5),
                'end' => substr((string) $row->end_time, 0, 5),
            ])
            ->all();
    }
}
