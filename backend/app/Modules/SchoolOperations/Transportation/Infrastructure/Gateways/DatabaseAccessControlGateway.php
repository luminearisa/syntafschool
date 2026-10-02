<?php

namespace App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Transportation\Application\Services\BoardingService;
use App\Modules\SchoolOperations\Transportation\Domain\Contracts\TransportationAccessProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * Penerima event perangkat (RFID/NFC/QR) dan penerbit token QR boarding.
 * Phase 2 tidak mendefinisikan protokol perangkat keras sendiri (§37).
 */
class DatabaseAccessControlGateway implements TransportationAccessProviderInterface
{
    use GatewaySupport;

    public function __construct(private readonly BoardingService $boarding)
    {
    }

    public function scan(array $payload): array
    {
        return $this->boarding->ingestScan($payload);
    }

    public function deviceAvailable(string $deviceId): bool
    {
        if (! $this->has('transportation_devices')) {
            return false;
        }

        return DB::table('transportation_devices')
            ->where('device_id', $deviceId)
            ->where('active', true)
            ->exists();
    }

    public function issueBoardingToken(int $studentId, int $tripId, array $options = []): array
    {
        return $this->boarding->issueBoardingToken($studentId, $tripId, $options);
    }

    public function verifyBoardingToken(string $token): array
    {
        return $this->boarding->verifyBoardingToken($token);
    }
}
