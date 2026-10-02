<?php

namespace App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\BoardingGatewayInterface;
use Illuminate\Support\Facades\DB;

class ActivityBoardingGateway implements BoardingGatewayInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        return $this->delegate('App\Modules\Activity\Domain\Contracts\BoardingProviderInterface');
    }

    public function isResident(int $studentId, ?string $onDate = null): bool
    {
        if ($provider = $this->provider()) {
            try {
                $placements = (array) $provider->placementsFor($studentId);
                foreach ($placements as $placement) {
                    $status = strtoupper((string) ($placement['status'] ?? ''));
                    if (in_array($status, ['ACTIVE', 'PLACED'], true) || $status === '') {
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
            ->whereIn('status', ['ACTIVE', 'PLACED'])
            ->exists();
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

    public function homeLeaveFor(int $studentId, string $date): ?array
    {
        if (! $this->has('boarding_homecomings')) {
            return null;
        }

        $row = DB::table('boarding_homecomings')
            ->where('student_id', $studentId)
            ->where(fn ($q) => $q->where('home_date', $date)->orWhere('return_date', $date))
            ->orderByDesc('id')
            ->first();

        return $row ? (array) $row : null;
    }

    public function available(): bool
    {
        return $this->provider() !== null || $this->has('boarding_placements');
    }
}
