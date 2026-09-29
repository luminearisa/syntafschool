<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Modules\LMS\Domain\Contracts\LiveMeetingProviderInterface;
use App\Modules\LMS\Domain\Enums\LiveSessionProvider;
use App\Modules\LMS\Models\LmsLiveSession;

/**
 * Provider paling sederhana: hanya menyimpan tautan yang diisi guru (§13, §17).
 *
 * Ini yang membuat semua fitur kelas langsung tetap jalan tanpa kredensial Zoom
 * atau Google. Kehadiran dan rekaman tidak pernah diklaim otomatis — guru
 * mencatat manual, atau provider lain yang benar-benar mengirim data.
 */
class LinkOnlyMeetingProvider implements LiveMeetingProviderInterface
{
    public function provider(): LiveSessionProvider
    {
        return LiveSessionProvider::EXTERNAL;
    }

    public function label(): string
    {
        return 'Tautan eksternal';
    }

    public function supportsAttendance(): bool
    {
        return false;
    }

    public function supportsRecording(): bool
    {
        return false;
    }

    public function supportsApiIntegration(): bool
    {
        return false;
    }

    public function joinUrl(LmsLiveSession $session, bool $asHost = false): ?string
    {
        $url = $asHost ? ($session->host_url ?? $session->meeting_url) : $session->meeting_url;

        return $url === null || trim((string) $url) === '' ? null : (string) $url;
    }

    public function createMeeting(LmsLiveSession $session, array $options = []): ?array
    {
        return null;
    }

    public function fetchAttendance(LmsLiveSession $session): array
    {
        return [];
    }
}
