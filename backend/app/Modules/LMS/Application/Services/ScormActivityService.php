<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Contracts\ActivityConfiguratorInterface;
use App\Modules\LMS\Domain\Support\LmsFault;
use App\Modules\LMS\Models\LmsActivity;
use App\Modules\LMS\Models\LmsScormPackage;
use App\Modules\LMS\Models\LmsScormSco;

/**
 * Konfigurasi aktivitas SCORM (§12).
 *
 * Konfigurasi disimpan pada `lms_activities.settings` — tidak ada tabel
 * konfigurasi kedua, dan wizard aktivitas tetap memakai kontrak
 * `ActivityConfiguratorInterface` yang sama dengan Assignment/Quiz/Forum.
 */
class ScormActivityService implements ActivityConfiguratorInterface
{
    public function createForActivity(LmsActivity $activity, array $data): void
    {
        $this->write($activity, $data);
    }

    public function updateForActivity(LmsActivity $activity, array $data): void
    {
        $this->write($activity, $data, false);
    }

    public function duplicateForActivity(LmsActivity $source, LmsActivity $copy): void
    {
        $copy->fill(['settings' => $source->settings])->save();
    }

    /** Konfigurasi SCORM sebuah aktivitas (null bila bukan aktivitas SCORM). */
    public function config(LmsActivity $activity): ?array
    {
        $settings = (array) ($activity->settings ?? []);

        if (! isset($settings['scorm_package_id'])) {
            return null;
        }

        return [
            'package_id' => (int) $settings['scorm_package_id'],
            'sco_id' => isset($settings['scorm_sco_id']) ? (int) $settings['scorm_sco_id'] : null,
            'version' => $settings['scorm_version'] ?? null,
            'launch_path' => $settings['launch_path'] ?? null,
            'tracking_mode' => $settings['tracking_mode'] ?? 'COMPLETION_AND_SCORE',
        ];
    }

    /** @param array<string, mixed> $data */
    private function write(LmsActivity $activity, array $data, bool $requirePackage = true): void
    {
        $packageId = $data['scorm_package_id'] ?? $data['package_id'] ?? null;

        if ($packageId === null) {
            if ($requirePackage) {
                LmsFault::invalid('Aktivitas SCORM memerlukan paket yang sudah diimpor.');
            }

            return;
        }

        $package = LmsScormPackage::query()->find((int) $packageId);

        if ($package === null || ! $package->isReady()) {
            LmsFault::invalid('Paket SCORM belum siap dipakai.');
        }

        $scoId = $data['scorm_sco_id'] ?? $data['sco_id'] ?? null;

        $sco = $scoId !== null
            ? LmsScormSco::query()->where('scorm_package_id', $package->id)->find((int) $scoId)
            : $package->scoes()->orderBy('id')->first();

        if ($sco === null) {
            LmsFault::invalid('Paket SCORM tidak memiliki SCO yang dapat diluncurkan.');
        }

        $settings = array_merge((array) $activity->settings, [
            'scorm_package_id' => (int) $package->id,
            'scorm_sco_id' => (int) $sco->id,
            'scorm_version' => $package->version?->value,
            'launch_path' => $sco->launch_path,
            'tracking_mode' => $data['tracking_mode'] ?? ($activity->settings['tracking_mode'] ?? 'COMPLETION_AND_SCORE'),
        ]);

        $activity->fill(['settings' => $settings])->save();
    }
}
