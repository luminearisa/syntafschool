<?php

namespace App\Modules\LMS\Infrastructure\Providers;

use App\Modules\LMS\Domain\Contracts\AcademicGradeProviderInterface;

/**
 * Provider mode mandiri (§63): tidak ada SIAKAD untuk ditulisi.
 *
 * Nilai tetap sah tersimpan di gradebook LMS; statusnya ditandai "tidak
 * berlaku (mandiri)" supaya tidak dianggap gagal sinkronisasi.
 */
class StandaloneAcademicGradeProvider implements AcademicGradeProviderInterface
{
    public function key(): string
    {
        return 'standalone';
    }

    public function label(): string
    {
        return 'Gradebook LMS (tanpa SIAKAD)';
    }

    /**
     * Tidak ada modul akademik untuk ditulisi: integrasi dianggap tidak
     * tersedia, dan justru itulah kontrak yang benar untuk mode mandiri (§63).
     */
    public function isAvailable(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $payload */
    public function push(array $payload): array
    {
        return [
            'accepted' => false,
            'external_type' => null,
            'external_id' => null,
            'external_ref' => null,
            'message' => 'Mode mandiri: LMS menjadi sumber nilai resmi, tidak ada pengiriman ke SIAKAD.',
            'locked' => false,
        ];
    }
}
