<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * AssetProviderInterface
 *
 * Tanda tangan kontrak ini SENGAJA dicerminkan dari:
 *   App\Modules\Asset\Domain\Contracts\AssetProviderInterface
 *
 * Dengan begitu delegasi menjadi passthrough tanpa lapisan terjemahan — tidak
 * ada nama metode yang perlu ditebak, yang merupakan sumber kegagalan senyap
 * paling mudah lolos ke produksi.
 *
 * Modul Event hanya melakukan orkestrasi; data pokok tetap milik modul asalnya.
 * Bila integrasi belum terpasang, penyedia wajib menyatakan
 * `isAvailable() === false` beserta alasannya, bukan mengarang data.
 */
interface AssetProviderInterface
{
    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function info(int $assetId): ?array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function available(array $filters): array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function lend(array $payload): array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function collect(array $payload): array;
    public function isAvailable(): bool;

    public function unavailableReason(): ?string;
}
