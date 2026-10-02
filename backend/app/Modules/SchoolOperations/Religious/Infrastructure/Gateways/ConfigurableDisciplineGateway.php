<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\DisciplineGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Student Discipline (§26).
 *
 * PENTING: presensi keagamaan TIDAK otomatis menjadi sanksi. Modul ini hanya
 * meneruskan FAKTA (Present/Late/Absent/Excused) bila institusi secara sadar
 * mengaktifkannya lewat `religious.integrations.discipline_enabled`.
 */
class ConfigurableDisciplineGateway implements DisciplineGatewayInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        return $this->delegate('App\\Modules\\Bk\\Domain\\Contracts\\DisciplineProviderInterface');
    }

    public function isEnabled(): bool
    {
        return (bool) config('religious.integrations.discipline_enabled', false)
            && $this->available();
    }

    public function forwardAttendanceFacts(array $payload): array
    {
        if (! $this->isEnabled()) {
            return [
                'forwarded' => false,
                'disabled' => true,
                'reason' => 'Penerusan presensi keagamaan ke kedisiplinan tidak diaktifkan oleh institusi.',
            ];
        }

        if ($provider = $this->provider()) {
            try {
                foreach (['record', 'report', 'createCase'] as $method) {
                    if (method_exists($provider, $method)) {
                        return ['forwarded' => true, 'result' => $provider->{$method}($payload)];
                    }
                }
            } catch (\Throwable $e) {
                return ['forwarded' => false, 'error' => $e->getMessage()];
            }
        }

        return ['forwarded' => false, 'reason' => $this->unavailableReason()];
    }

    public function available(): bool
    {
        return $this->provider() !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Modul kedisiplinan siswa tidak tersedia pada instalasi ini.';
    }
}
