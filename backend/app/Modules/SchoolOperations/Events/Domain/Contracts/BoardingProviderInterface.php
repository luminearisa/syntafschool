<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * BoardingProviderInterface
 *
 * Tanda tangan kontrak ini SENGAJA dicerminkan dari:
 *   App\Modules\Activity\Domain\Contracts\BoardingProviderInterface
 *
 * Dengan begitu delegasi menjadi passthrough tanpa lapisan terjemahan — tidak
 * ada nama metode yang perlu ditebak, yang merupakan sumber kegagalan senyap
 * paling mudah lolos ke produksi.
 *
 * Modul Event hanya melakukan orkestrasi; data pokok tetap milik modul asalnya.
 * Bila integrasi belum terpasang, penyedia wajib menyatakan
 * `isAvailable() === false` beserta alasannya, bukan mengarang data.
 */
interface BoardingProviderInterface
{
    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function obligationsFor(int $studentId, string $date): array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function placementsFor(int $studentId): array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function weeklyObligations(): array;
    public function isAvailable(): bool;

    public function unavailableReason(): ?string;
}
