<?php

namespace App\Modules\Asset\Infrastructure\Providers;

use App\Modules\Asset\Domain\Contracts\StudentActivityProviderInterface;

/**
 * Penyedia bawaan (kosong) untuk modul yang belum menyediakan kontraknya.
 *
 * Dinyatakan apa adanya: selama modulnya belum ada, modul Asset tetap berjalan
 * penuh untuk siklus hidupnya sendiri, dan integrasi yang belum tersedia TIDAK
 * berpura-pura berhasil.
 */
class NullStudentActivityProvider implements StudentActivityProviderInterface
{
    public function activity(int $activityId): ?array
    {
        return null;
    }

    public function openCommitments(int $activityId): array
    {
        return [];
    }

    public function attachAsset(int $activityId, array $assetPayload): array
    {
        return ['status' => 'PENDING'];
    }
}
