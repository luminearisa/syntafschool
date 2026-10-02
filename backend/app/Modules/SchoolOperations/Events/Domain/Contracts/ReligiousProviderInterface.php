<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * ReligiousProviderInterface
 *
 * Tanda tangan kontrak ini SENGAJA dicerminkan dari:
 *   App\Modules\SchoolOperations\Religious\Domain\Contracts\ReligiousLifeProviderInterface
 *
 * Dengan begitu delegasi menjadi passthrough tanpa lapisan terjemahan — tidak
 * ada nama metode yang perlu ditebak, yang merupakan sumber kegagalan senyap
 * paling mudah lolos ke produksi.
 *
 * Modul Event hanya melakukan orkestrasi; data pokok tetap milik modul asalnya.
 * Bila integrasi belum terpasang, penyedia wajib menyatakan
 * `isAvailable() === false` beserta alasannya, bukan mengarang data.
 */
interface ReligiousProviderInterface
{
    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function prayerTimesFor(string $date, ?int $mosqueId = null): array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function nextPrayer(?int $mosqueId = null, ?string $at = null): ?array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function todayActivities(?int $mosqueId = null, ?string $date = null): array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function isRamadan(?string $date = null): bool;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function mosquesFor(?int $campusId = null): array;
    public function isAvailable(): bool;

    public function unavailableReason(): ?string;
}
