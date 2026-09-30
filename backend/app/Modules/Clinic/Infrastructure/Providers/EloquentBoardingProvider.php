<?php

namespace App\Modules\Clinic\Infrastructure\Providers;

use App\Modules\Clinic\Domain\Contracts\BoardingProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * Konteks asrama dari modul Boarding.
 *
 * Hanya membaca. Clinic tidak mengubah penempatan kamar atau status asrama:
 * keputusan itu milik modul Boarding dengan aturan bisnisnya sendiri. Yang
 * disediakan Clinic adalah **saran** (istirahat di kamar) yang harus
 * ditindaklanjuti petugas asrama.
 */
class EloquentBoardingProvider implements BoardingProviderInterface
{
    public function placementForStudent(int $studentId): ?array
    {
        $row = DB::table('boarding_placements')
            ->leftJoin('boarding_dormitories', 'boarding_dormitories.id', '=', 'boarding_placements.dormitory_id')
            ->leftJoin('boarding_rooms', 'boarding_rooms.id', '=', 'boarding_placements.room_id')
            ->leftJoin('boarding_beds', 'boarding_beds.id', '=', 'boarding_placements.bed_id')
            ->where('boarding_placements.student_id', $studentId)
            ->where('boarding_placements.status', 'active')
            ->orderByDesc('boarding_placements.start_date')
            ->first([
                'boarding_dormitories.name as dormitory_name',
                'boarding_rooms.name as room_name',
                'boarding_beds.bed_number as bed_number',
            ]);

        if ($row === null) {
            return null;
        }

        return [
            'dormitory' => $row->dormitory_name,
            'room' => $row->room_name,
            'bed' => $row->bed_number,
            'is_boarder' => true,
        ];
    }

    public function isBoarder(int $studentId): bool
    {
        return DB::table('boarding_placements')
            ->where('student_id', $studentId)
            ->where('status', 'active')
            ->exists();
    }

    public function recommendRoomRest(int $studentId, string $reason): array
    {
        if (! $this->isBoarder($studentId)) {
            return [
                'allowed' => false,
                'requires_supervision' => false,
                'note' => 'Siswa bukan penghuni asrama; istirahat diatur di rumah.',
            ];
        }

        return [
            'allowed' => true,
            'requires_supervision' => false,
            'note' => 'Saran klinis: istirahat di kamar — '.$reason.'. Perubahan status asrama tetap lewat modul Boarding.',
        ];
    }
}
