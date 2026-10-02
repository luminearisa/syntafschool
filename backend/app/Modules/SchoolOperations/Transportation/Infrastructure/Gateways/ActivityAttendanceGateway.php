<?php

namespace App\Modules\SchoolOperations\Transportation\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Transportation\Domain\Contracts\AttendanceGatewayInterface;

/**
 * Menitipkan bukti kehadiran transportasi ke modul presensi.
 * Transportasi tidak pernah menjadi master presensi (§36).
 */
class ActivityAttendanceGateway implements AttendanceGatewayInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        return $this->delegate('App\Modules\Activity\Domain\Contracts\AttendanceProviderInterface')
            ?? $this->delegate('App\Modules\Hrd\Domain\Contracts\AttendanceProviderInterface');
    }

    public function mirror(array $payload): ?string
    {
        $provider = $this->provider();
        if (! $provider) {
            return null;
        }

        try {
            return $provider->mirror($payload);
        } catch (\Throwable) {
            return null;
        }
    }

    public function revoke(string $reference): bool
    {
        $provider = $this->provider();
        if (! $provider) {
            return false;
        }

        try {
            return (bool) $provider->revoke($reference);
        } catch (\Throwable) {
            return false;
        }
    }

    public function available(): bool
    {
        return $this->provider() !== null;
    }
}
