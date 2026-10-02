<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\BoardingGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Asrama (§24, §69). Wajib untuk presensi shalat berjamaah santri.
 */
class ModuleBoardingGateway implements BoardingGatewayInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        return $this->delegate('App\\Modules\\Activity\\Domain\\Contracts\\BoardingProviderInterface')
            ?? $this->delegate('App\\Modules\\Bk\\Domain\\Contracts\\BoardingProviderInterface')
            ?? $this->delegate('App\\Modules\\Clinic\\Domain\\Contracts\\BoardingProviderInterface');
    }

    public function isResident(int $studentId, ?string $onDate = null): bool
    {
        if ($provider = $this->provider()) {
            try {
                $placements = (array) $provider->placementsFor($studentId);
                foreach ($placements as $placement) {
                    // Status dapat ditulis kapital atau huruf kecil oleh modul lain.
                    $status = strtoupper((string) ($placement['status'] ?? ''));
                    if (in_array($status, ['ACTIVE', 'PLACED', 'IN_PROGRESS', ''], true)) {
                        return true;
                    }
                }
            } catch (\Throwable) {
            }
        }

        if (! $this->has('boarding_placements')) {
            return false;
        }

        return DB::table('boarding_placements')
            ->where('student_id', $studentId)
            ->when($onDate, fn ($q) => $q->where(fn ($inner) => $inner
                ->whereNull('end_date')->orWhere('end_date', '>=', $onDate)))
            ->whereIn(DB::raw('lower(status)'), ['active', 'placed', 'in_progress'])
            ->exists();
    }

    public function dormitories(array $filters = []): array
    {
        $table = $this->firstTable(['boarding_dormitories', 'dormitories', 'boarding_rooms']);
        if ($table === null) {
            return [];
        }

        $query = DB::table($table);
        $columns = $this->columns($table);
        if (! empty($filters['campus_id']) && in_array('campus_id', $columns, true)) {
            $query->where('campus_id', $filters['campus_id']);
        }

        return array_map(fn ($row): array => (array) $row, $query->get()->all());
    }

    public function residentsOf(int $dormitoryId, array $filters = []): array
    {
        if (! $this->has('boarding_placements')) {
            return [];
        }

        $columns = $this->columns('boarding_placements');
        $dormColumn = $this->pick($columns, ['dormitory_id', 'room_id', 'boarding_room_id']);
        if ($dormColumn === null) {
            return [];
        }

        $rows = DB::table('boarding_placements')->where($dormColumn, $dormitoryId)
            ->whereIn(DB::raw('lower(status)'), ['active', 'placed', 'in_progress'])
            ->limit((int) ($filters['limit'] ?? 2000))->get();

        return array_map(fn ($row): array => (array) $row, $rows->all());
    }

    public function obligationsFor(int $studentId, string $date): array
    {
        if ($provider = $this->provider()) {
            try {
                return (array) $provider->obligationsFor($studentId, $date);
            } catch (\Throwable) {
            }
        }

        return [];
    }

    public function available(): bool
    {
        return $this->provider() !== null
            || $this->has('boarding_placements')
            || $this->has('boarding_dormitories');
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Modul asrama tidak tersedia pada instalasi ini.';
    }
}
