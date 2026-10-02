<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Providers;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\PrayerTimeProviderInterface;

/**
 * Penyedia jadwal dari KONFIGURASI INSTITUSI (§12, §13).
 *
 * Mendukung penjadwalan berbeda per musim/Ramadan/kebijakan institusi, serta
 * penimpaan (override) waktu iqamah tanpa mengubah kode.
 *
 * Struktur `config/religious.php`:
 *   prayer_time.static_schedule.default  => [FAJR => ['adhan' => '04:35', ...]]
 *   prayer_time.static_schedule.ramadan  => [...]
 *   prayer_time.static_schedule.months.7 => [...]
 */
class ConfigPrayerTimeProvider implements PrayerTimeProviderInterface
{
    public function providerCode(): string
    {
        return 'CONFIGURATION';
    }

    public function scheduleFor(string $date, array $options = []): array
    {
        $table = $this->tableFor($date);
        if ($table === []) {
            return [];
        }

        $out = [];
        foreach ($table as $prayerType => $times) {
            if (! is_array($times)) {
                continue;
            }
            $out[] = [
                'prayer_type' => strtoupper((string) $prayerType),
                'adhan_time' => $times['adhan'] ?? null,
                'iqamah_time' => $times['iqamah'] ?? null,
                'start_time' => $times['start'] ?? ($times['adhan'] ?? null),
                'end_time' => $times['end'] ?? null,
                'status' => 'SCHEDULED',
                'source' => 'CONFIGURATION',
            ];
        }

        return $out;
    }

    public function rangeFor(string $startDate, string $endDate, array $options = []): array
    {
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
            'source' => 'CONFIGURATION',
            'label' => 'Konfigurasi institusi',
            'supports_ramadan' => true,
            'supports_iqamah' => true,
            'supports_seasonal' => true,
            'requires_network' => false,
        ];
    }

    public function available(): bool
    {
        return $this->baseTable() !== [];
    }

    public function unavailableReason(): ?string
    {
        return $this->available()
            ? null
            : 'Jadwal statis pada konfigurasi institusi belum diisi.';
    }

    /** Pilih tabel jadwal: Ramadan → bulan → default. */
    protected function tableFor(string $date): array
    {
        $config = (array) config('religious.prayer_time.static_schedule', []);
        $carbon = \Carbon\Carbon::parse($date);

        if ($this->isRamadan($carbon)) {
            $ramadan = (array) ($config['ramadan'] ?? []);
            if ($ramadan !== []) {
                return $ramadan;
            }
        }

        $months = (array) ($config['months'] ?? []);
        $monthKey = (string) $carbon->month;
        if (isset($months[$monthKey]) && is_array($months[$monthKey])) {
            return $months[$monthKey];
        }

        return (array) ($config['default'] ?? []);
    }

    protected function baseTable(): array
    {
        $config = (array) config('religious.prayer_time.static_schedule', []);

        return (array) ($config['default'] ?? []);
    }

    protected function isRamadan(\Carbon\Carbon $date): bool
    {
        return (bool) config('religious.calendar.auto_ramadan_from_program', true)
            ? app(\App\Modules\SchoolOperations\Religious\Application\Services\RamadanService::class)
                ->coversDate($date->toDateString())
            : false;
    }
}
