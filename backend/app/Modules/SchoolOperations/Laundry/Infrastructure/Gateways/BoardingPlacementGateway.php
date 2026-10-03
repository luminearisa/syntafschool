<?php

namespace App\Modules\SchoolOperations\Laundry\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Laundry\Domain\Contracts\BoardingProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * Asrama: penempatan aktif santri dari `boarding_placements` (Boarding tetap
 * sumber kebenaran). Dipakai untuk scope policy (dormitory/kategori BOARDING)
 * dan tujuan pengantaran laundry ke asrama (§43).
 */
class BoardingPlacementGateway implements BoardingProviderInterface
{
    use GatewaySupport;

    public function isResident(int $studentId, ?string $onDate = null): bool
    {
        return $this->placementFor($studentId, $onDate) !== null;
    }

    public function placementFor(int $studentId, ?string $onDate = null): ?array
    {
        if (! $this->has('boarding_placements')) {
            return null;
        }

        $date = $onDate ?: now()->toDateString();

        $row = DB::table('boarding_placements')
            ->where('student_id', $studentId)
            ->where('status', 'active')
            ->where('start_date', '<=', $date)
            ->where(function ($q) use ($date): void {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $date);
            })
            ->orderByDesc('id')
            ->first();

        if (! $row) {
            return null;
        }

        $dormitory = $this->row('boarding_dormitories', (int) $row->dormitory_id);

        return [
            'placement_id' => (int) $row->id,
            'dormitory_id' => (int) $row->dormitory_id,
            'dormitory_name' => $dormitory['name'] ?? null,
            'building_id' => isset($row->building_id) ? (int) $row->building_id : null,
            'room_id' => isset($row->room_id) ? (int) $row->room_id : null,
            'bed_id' => isset($row->bed_id) ? (int) $row->bed_id : null,
        ];
    }

    public function available(): bool
    {
        return $this->has('boarding_placements');
    }
}
