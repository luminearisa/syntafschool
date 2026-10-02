<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * AttendanceProviderInterface
 *
 * Tanda tangan kontrak ini SENGAJA dicerminkan dari:
 *   App\Modules\Activity\Domain\Contracts\AttendanceProviderInterface
 *
 * Dengan begitu delegasi menjadi passthrough tanpa lapisan terjemahan — tidak
 * ada nama metode yang perlu ditebak, yang merupakan sumber kegagalan senyap
 * paling mudah lolos ke produksi.
 *
 * Modul Event hanya melakukan orkestrasi; data pokok tetap milik modul asalnya.
 * Bila integrasi belum terpasang, penyedia wajib menyatakan
 * `isAvailable() === false` beserta alasannya, bukan mengarang data.
 */
interface AttendanceProviderInterface
{
    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function mirror(array $payload): ?string;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function revoke(string $reference): bool;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function summary(int $studentId, string $from, string $to): array;
    public function isAvailable(): bool;

    public function unavailableReason(): ?string;
}
