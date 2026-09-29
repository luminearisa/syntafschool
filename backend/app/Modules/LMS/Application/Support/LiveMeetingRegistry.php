<?php

namespace App\Modules\LMS\Application\Support;

use App\Modules\LMS\Domain\Contracts\LiveMeetingProviderInterface;
use App\Modules\LMS\Domain\Enums\LiveSessionProvider;
use App\Modules\LMS\Infrastructure\Providers\LinkOnlyMeetingProvider;

/**
 * Registry penyedia pertemuan daring (§17).
 *
 * `LiveSessionService` tidak pernah menulis `if ($provider === 'ZOOM') …`.
 * Provider baru cukup didaftarkan di sini; bila belum ada implementasinya,
 * registry mengembalikan provider tautan-saja sehingga kelas tetap dapat
 * dijadwalkan (tidak ada fitur yang gagal total hanya karena integrasi API
 * belum tersedia).
 */
class LiveMeetingRegistry
{
    /** @var array<string, LiveMeetingProviderInterface> */
    private array $providers = [];

    public function __construct(?LiveMeetingProviderInterface $fallback = null)
    {
        $this->providers[LiveSessionProvider::EXTERNAL->value] = $fallback ?? new LinkOnlyMeetingProvider;
    }

    public function register(LiveMeetingProviderInterface $provider): void
    {
        $this->providers[$provider->provider()->value] = $provider;
    }

    /** Implementasi untuk provider tertentu, dengan fallback tautan-saja. */
    public function for(LiveSessionProvider $provider): LiveMeetingProviderInterface
    {
        return $this->providers[$provider->value]
            ?? $this->providers[LiveSessionProvider::EXTERNAL->value];
    }

    public function has(LiveSessionProvider $provider): bool
    {
        return isset($this->providers[$provider->value]);
    }

    public function default(): LiveMeetingProviderInterface
    {
        return $this->for(LiveSessionProvider::from(
            (string) config('lms.live.default_provider', LiveSessionProvider::EXTERNAL->value),
        ));
    }

    /** @return list<array<string, mixed>> daftar provider untuk UI pengaturan */
    public function describe(): array
    {
        return array_map(function (LiveSessionProvider $provider): array {
            $implementation = $this->for($provider);

            return [
                'value' => $provider->value,
                'label' => $provider->label(),
                'configured' => $this->has($provider),
                'api' => $implementation->supportsApiIntegration(),
                'attendance' => $implementation->supportsAttendance(),
                'recording' => $implementation->supportsRecording(),
            ];
        }, LiveSessionProvider::cases());
    }
}
