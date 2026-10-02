<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * CalendarProviderInterface
 *
 * Tanda tangan kontrak ini SENGAJA dicerminkan dari:
 *   App\Modules\LMS\Domain\Contracts\CalendarProviderInterface
 *
 * Dengan begitu delegasi menjadi passthrough tanpa lapisan terjemahan — tidak
 * ada nama metode yang perlu ditebak, yang merupakan sumber kegagalan senyap
 * paling mudah lolos ke produksi.
 *
 * Modul Event hanya melakukan orkestrasi; data pokok tetap milik modul asalnya.
 * Bila integrasi belum terpasang, penyedia wajib menyatakan
 * `isAvailable() === false` beserta alasannya, bukan mengarang data.
 */
interface CalendarProviderInterface
{
    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function key(): string;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function isAvailable(): bool;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function eventsBetween(string $from, string $to, ?int $classId = null, ?int $academicYearId = null): array;
    public function unavailableReason(): ?string;
}
