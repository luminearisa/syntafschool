<?php

namespace App\Modules\Bk\Infrastructure\Providers;

use App\Modules\Activity\Domain\Contracts\CounselingProviderInterface;
use App\Modules\Bk\Domain\Contracts\StudentActivityProviderInterface;

/**
 * Sinyal perkembangan dari Modul Activity.
 *
 * Modul Activity sudah menyediakan kontrak sempit (`CounselingProviderInterface`)
 * yang hanya membuka ringkasan capaian — bukan catatan internal aktivitas. BK
 * meneruskannya apa adanya, termasuk keputusan "boleh dibagikan atau tidak".
 */
class ActivitySignalProvider implements StudentActivityProviderInterface
{
    public function __construct(private readonly ?CounselingProviderInterface $activity = null)
    {
        // Bila modul Activity tidak terpasang, kontrak tidak terikat dan properti
        // tetap null — BK berjalan tanpa sinyal aktivitas, bukan gagal.
    }

    /** Apakah modul Activity memasok sinyal untuk BK. */
    public function available(): bool
    {
        return $this->activity !== null;
    }

    public function developmentSignals(int $studentId, int $userId): array
    {
        $activity = $this->activity ?? $this->resolve();

        if ($activity === null || ! $activity->canExposeSummary($userId, $studentId)) {
            return [];
        }

        return $activity->developmentSignals($studentId);
    }

    /** Penyelesaian malas: urutan registrasi provider tidak boleh jadi penentu. */
    private function resolve(): ?CounselingProviderInterface
    {
        if (! class_exists(CounselingProviderInterface::class)) {
            return null;
        }

        try {
            return app(CounselingProviderInterface::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
