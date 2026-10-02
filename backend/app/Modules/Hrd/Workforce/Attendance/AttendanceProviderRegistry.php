<?php

namespace App\Modules\Hrd\Workforce\Attendance;

use App\Modules\Hrd\Core\Domain\Contracts\AttendanceDeviceProviderInterface;
use App\Modules\Hrd\Workforce\Attendance\Providers\DeviceAttendanceProvider;
use App\Modules\Hrd\Workforce\Attendance\Providers\HumanInputAttendanceProvider;
use InvalidArgumentException;

/**
 * Registri penyedia presensi (§21).
 *
 * Menambah perangkat baru cukup dengan mendaftarkan penyedia baru di sini —
 * tidak ada `switch ($jenisPerangkat)` yang tersebar di dalam layanan presensi.
 */
class AttendanceProviderRegistry
{
    /** @var array<string, AttendanceDeviceProviderInterface> */
    private array $providers = [];

    public function __construct()
    {
        foreach (['manual', 'web', 'mobile'] as $code) {
            $this->register(new HumanInputAttendanceProvider($code));
        }

        foreach (['qr', 'nfc', 'rfid', 'fingerprint', 'face', 'device_api'] as $code) {
            $this->register(new DeviceAttendanceProvider($code));
        }
    }

    public function register(AttendanceDeviceProviderInterface $provider): void
    {
        $this->providers[strtolower($provider->code())] = $provider;
    }

    public function resolve(string $code): AttendanceDeviceProviderInterface
    {
        $code = strtolower($code);

        if (! isset($this->providers[$code])) {
            throw new InvalidArgumentException("Penyedia presensi \"{$code}\" belum terdaftar.");
        }

        return $this->providers[$code];
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->providers);
    }
}
