<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Infrastructure\Providers;

/**
 * Kegiatan siswa & prestasi (modul Student Activity).
 *
 * Meneruskan ke kontrak StudentActivity untuk komitmen kegiatan siswa terkait event (§107).
 */
class AssetStudentActivityProvider
{
    use ProviderSupport;

    protected const UNAVAILABLE_REASON = 'Meneruskan ke kontrak StudentActivity untuk komitmen kegiatan siswa terkait event (§107).';

    protected const TARGET_CONTRACT = 'App\\Modules\\Asset\\Domain\\Contracts\\StudentActivityProviderInterface';

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function activity(int $activityId): ?array
    {
        $value = $this->call('activity', [$activityId]);

        return $value === null ? null : (array) $value;
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function openCommitments(int $activityId): array
    {
        $value = $this->call('openCommitments', [$activityId]);

        return $this->wrap($value);
    }

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function attachAsset(int $activityId, array $assetPayload): array
    {
        $value = $this->call('attachAsset', [$activityId, $assetPayload]);

        return $this->wrap($value);
    }
}
