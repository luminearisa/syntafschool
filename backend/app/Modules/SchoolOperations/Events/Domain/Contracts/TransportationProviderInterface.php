<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * TransportationProviderInterface
 *
 * Tanda tangan kontrak ini SENGAJA dicerminkan dari:
 *   App\Modules\Activity\Domain\Contracts\TransportationProviderInterface
 *
 * Dengan begitu delegasi menjadi passthrough tanpa lapisan terjemahan — tidak
 * ada nama metode yang perlu ditebak, yang merupakan sumber kegagalan senyap
 * paling mudah lolos ke produksi.
 *
 * Modul Event hanya melakukan orkestrasi; data pokok tetap milik modul asalnya.
 * Bila integrasi belum terpasang, penyedia wajib menyatakan
 * `isAvailable() === false` beserta alasannya, bukan mengarang data.
 */
interface TransportationProviderInterface
{
    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function request(array $payload): array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function cancel(string $reference): bool;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function isAvailable(): bool;
    public function unavailableReason(): ?string;
}
