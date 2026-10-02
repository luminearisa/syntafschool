<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * StudentActivityProviderInterface
 *
 * Tanda tangan kontrak ini SENGAJA dicerminkan dari:
 *   App\Modules\Asset\Domain\Contracts\StudentActivityProviderInterface
 *
 * Dengan begitu delegasi menjadi passthrough tanpa lapisan terjemahan — tidak
 * ada nama metode yang perlu ditebak, yang merupakan sumber kegagalan senyap
 * paling mudah lolos ke produksi.
 *
 * Modul Event hanya melakukan orkestrasi; data pokok tetap milik modul asalnya.
 * Bila integrasi belum terpasang, penyedia wajib menyatakan
 * `isAvailable() === false` beserta alasannya, bukan mengarang data.
 */
interface StudentActivityProviderInterface
{
    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function activity(int $activityId): ?array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function openCommitments(int $activityId): array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function attachAsset(int $activityId, array $assetPayload): array;
    public function isAvailable(): bool;

    public function unavailableReason(): ?string;
}
