<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * FacilityProviderInterface
 *
 * Tanda tangan kontrak ini SENGAJA dicerminkan dari:
 *   App\Modules\Activity\Domain\Contracts\FacilityProviderInterface
 *
 * Dengan begitu delegasi menjadi passthrough tanpa lapisan terjemahan — tidak
 * ada nama metode yang perlu ditebak, yang merupakan sumber kegagalan senyap
 * paling mudah lolos ke produksi.
 *
 * Modul Event hanya melakukan orkestrasi; data pokok tetap milik modul asalnya.
 * Bila integrasi belum terpasang, penyedia wajib menyatakan
 * `isAvailable() === false` beserta alasannya, bukan mengarang data.
 */
interface FacilityProviderInterface
{
    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function room(int $roomId): ?array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function rooms(array $filters = []): array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function availability(int $roomId, string $date, string $startTime, string $endTime, ?int $ignoreBookingId = null): bool;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function book(array $payload): array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function cancel(string $reference): bool;
    public function isAvailable(): bool;

    public function unavailableReason(): ?string;
}
