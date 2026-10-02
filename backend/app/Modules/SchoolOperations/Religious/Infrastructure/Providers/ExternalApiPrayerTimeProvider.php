<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Providers;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\PrayerTimeProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Penyedia jadwal dari API EKSTERNAL (§12, §100).
 *
 * Nonaktif secara bawaan. Bila diaktifkan, jadwal diambil dari layanan waktu
 * shalat lalu disimpan sementara di cache agar tidak memanggil jaringan terus.
 * Kegagalan jaringan tidak boleh menghentikan ibadah: pemanggil akan memakai
 * penyedia berikutnya pada rantai prioritas.
 */
class ExternalApiPrayerTimeProvider implements PrayerTimeProviderInterface
{
    public function providerCode(): string
    {
        return 'EXTERNAL_API';
    }

    public function scheduleFor(string $date, array $options = []): array
    {
        if (! $this->available()) {
            return [];
        }

        $city = (string) config('religious.prayer_time.api.city', 'Jakarta');
        $cacheKey = 'religious:prayer-time:'.md5($city.$date.config('religious.prayer_time.api.method', '11'));
        $ttl = (int) config('religious.prayer_time.api.cache_minutes', 720);

        return (array) Cache::remember($cacheKey, now()->addMinutes($ttl), function () use ($city, $date, $options): array {
            try {
                $response = Http::timeout((int) config('religious.prayer_time.api.timeout', 8))
                    ->get(rtrim((string) config('religious.prayer_time.api.base_url'), '/').'/timings/'.$date, [
                        'city' => $city,
                        'method' => config('religious.prayer_time.api.method', '11'),
                    ]);

                if (! $response->successful()) {
                    return [];
                }

                return $this->normalize((array) $response->json(), $date);
            } catch (\Throwable $e) {
                \Log::warning('religious.prayer_time.api_failed', ['message' => $e->getMessage()]);

                return [];
            }
        });
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
            'source' => 'EXTERNAL_API',
            'label' => 'Layanan waktu shalat eksternal',
            'enabled' => (bool) config('religious.prayer_time.api.enabled', false),
            'requires_network' => true,
            'cached' => true,
        ];
    }

    public function available(): bool
    {
        return (bool) config('religious.prayer_time.api.enabled', false)
            && (string) config('religious.prayer_time.api.base_url', '') !== '';
    }

    public function unavailableReason(): ?string
    {
        return $this->available()
            ? null
            : 'Sumber API eksternal tidak diaktifkan pada konfigurasi institusi.';
    }

    protected function normalize(array $payload, string $date): array
    {
        $timings = (array) ($payload['data']['timings'] ?? $payload['timings'] ?? []);
        if ($timings === []) {
            return [];
        }

        $map = (array) config('religious.prayer_time.api.field_map', [
            'FAJR' => 'Fajr', 'DHUHR' => 'Dhuhr', 'ASR' => 'Asr',
            'MAGHRIB' => 'Maghrib', 'ISHA' => 'Isha',
        ]);

        $out = [];
        foreach ($map as $prayerType => $sourceKey) {
            $value = $timings[$sourceKey] ?? null;
            if ($value === null) {
                continue;
            }

            $out[] = [
                'prayer_type' => $prayerType,
                'adhan_time' => substr((string) $value, 0, 5),
                'iqamah_time' => null,
                'start_time' => substr((string) $value, 0, 5),
                'end_time' => null,
                'status' => 'SCHEDULED',
                'source' => 'EXTERNAL_API',
            ];
        }

        return $out;
    }
}
