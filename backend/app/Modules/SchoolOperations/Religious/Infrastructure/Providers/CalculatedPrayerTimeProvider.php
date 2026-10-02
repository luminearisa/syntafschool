<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Providers;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\PrayerTimeProviderInterface;

/**
 * Penyedia jadwal dari KALKULASI ASTRONOMI (§12, §100).
 *
 * Batas Phase 3: sistem TIDAK memuat mesin hisab kompleks di dalam inti modul.
 * Kelas ini adalah titik integrasi: bila institusi memasang kalkulator
 * (mis. pustaka hisab) lewat `religious.prayer_time.calculator`, jadwal
 * diambil dari sana. Tanpa kalkulator, penyedia menyatakan tidak tersedia
 * alih-alih menebak waktu ibadah.
 */
class CalculatedPrayerTimeProvider implements PrayerTimeProviderInterface
{
    public function providerCode(): string
    {
        return 'CALCULATION';
    }

    public function scheduleFor(string $date, array $options = []): array
    {
        $calculator = $this->calculator();
        if ($calculator === null) {
            return [];
        }

        try {
            $result = $calculator->calculate($date, array_merge($options, [
                'latitude' => $options['latitude'] ?? config('religious.prayer_time.latitude'),
                'longitude' => $options['longitude'] ?? config('religious.prayer_time.longitude'),
                'timezone' => $options['timezone'] ?? config('religious.prayer_time.timezone', 'Asia/Jakarta'),
                'method' => $options['method'] ?? config('religious.prayer_time.calculation_method', 'KEMENAG'),
            ]));

            return is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            \Log::warning('religious.prayer_time.calculator_failed', ['message' => $e->getMessage()]);

            return [];
        }
    }

    public function rangeFor(string $startDate, string $endDate, array $options = []): array
    {
        $calculator = $this->calculator();
        if ($calculator === null) {
            return [];
        }

        if (method_exists($calculator, 'range')) {
            try {
                $result = $calculator->range($startDate, $endDate, $options);

                return is_array($result) ? $result : [];
            } catch (\Throwable) {
            }
        }

        $out = [];
        $cursor = \Carbon\Carbon::parse($startDate);
        $end = \Carbon\Carbon::parse($endDate);
        while ($cursor->lte($end)) {
            $out[$cursor->toDateString()] = $this->scheduleFor($cursor->toDateString(), $options);
            $cursor->addDay();
        }

        return $out;
    }

    public function capabilities(): array
    {
        return [
            'source' => 'CALCULATION',
            'label' => 'Kalkulasi astronomi institusi',
            'calculator_installed' => $this->calculator() !== null,
            'supports_ramadan' => true,
            'requires_network' => false,
        ];
    }

    public function available(): bool
    {
        return $this->calculator() !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->available()
            ? null
            : 'Kalkulator hisab belum dipasang; isi jadwal manual atau aktifkan sumber lain.';
    }

    protected function calculator(): ?object
    {
        $class = (string) config('religious.prayer_time.calculator', '');
        if ($class === '' || ! class_exists($class)) {
            return null;
        }

        try {
            return app($class);
        } catch (\Throwable) {
            return null;
        }
    }
}
