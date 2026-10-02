<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Providers;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\PrayerTimeProviderInterface;

/**
 * Penyedia gabungan (§12): mencoba setiap penyedia sesuai prioritas
 * konfigurasi sehingga sistem tidak terkunci pada satu sumber jadwal.
 *
 * Urutan bawaan: MANUAL → CONFIGURATION → CALCULATION → EXTERNAL_API.
 * Sumber pertama yang mengembalikan jadwal dipakai; pemenang dicatat sebagai
 * `source` agar audit jadwal dapat ditelusuri (§13).
 */
class CompositePrayerTimeProvider implements PrayerTimeProviderInterface
{
    /** @param PrayerTimeProviderInterface[] $providers */
    public function __construct(protected array $providers = [])
    {
    }

    public function providerCode(): string
    {
        return 'COMPOSITE';
    }

    public function scheduleFor(string $date, array $options = []): array
    {
        foreach ($this->ordered() as $provider) {
            if (! $provider->available()) {
                continue;
            }

            $result = $provider->scheduleFor($date, $options);
            if ($result !== []) {
                return array_map(fn (array $row): array => array_merge($row, [
                    'resolved_by' => $provider->providerCode(),
                ]), $result);
            }
        }

        return [];
    }

    public function rangeFor(string $startDate, string $endDate, array $options = []): array
    {
        $out = [];
        $cursor = \Carbon\Carbon::parse($startDate);
        $end = \Carbon\Carbon::parse($endDate);
        $guard = 0;

        while ($cursor->lte($end) && $guard < 400) {
            $out[$cursor->toDateString()] = $this->scheduleFor($cursor->toDateString(), $options);
            $cursor->addDay();
            $guard++;
        }

        return $out;
    }

    public function capabilities(): array
    {
        return [
            'source' => 'COMPOSITE',
            'label' => 'Rantai penyedia jadwal',
            'chain' => array_map(fn (PrayerTimeProviderInterface $p): array => [
                'code' => $p->providerCode(),
                'available' => $p->available(),
                'reason' => $p->unavailableReason(),
            ], $this->ordered()),
        ];
    }

    public function available(): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->available()) {
                return true;
            }
        }

        return false;
    }

    public function unavailableReason(): ?string
    {
        return $this->available()
            ? null
            : 'Tidak ada sumber jadwal shalat yang tersedia pada instalasi ini.';
    }

    /** @return PrayerTimeProviderInterface[] */
    protected function ordered(): array
    {
        $priority = array_map('strtoupper', (array) config('religious.prayer_time.priority',
            ['MANUAL', 'CONFIGURATION', 'CALCULATION', 'EXTERNAL_API']));

        $byCode = [];
        foreach ($this->providers as $provider) {
            $byCode[$provider->providerCode()] = $provider;
        }

        $ordered = [];
        foreach ($priority as $code) {
            if (isset($byCode[$code])) {
                $ordered[] = $byCode[$code];
                unset($byCode[$code]);
            }
        }

        return array_merge($ordered, array_values($byCode));
    }
}
