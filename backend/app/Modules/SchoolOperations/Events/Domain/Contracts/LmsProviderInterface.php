<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * LmsProviderInterface
 *
 * Tanda tangan kontrak ini SENGAJA dicerminkan dari:
 *   App\Modules\Activity\Domain\Contracts\LmsProviderInterface
 *
 * Dengan begitu delegasi menjadi passthrough tanpa lapisan terjemahan — tidak
 * ada nama metode yang perlu ditebak, yang merupakan sumber kegagalan senyap
 * paling mudah lolos ke produksi.
 *
 * Modul Event hanya melakukan orkestrasi; data pokok tetap milik modul asalnya.
 * Bila integrasi belum terpasang, penyedia wajib menyatakan
 * `isAvailable() === false` beserta alasannya, bukan mengarang data.
 */
interface LmsProviderInterface
{
    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function course(int $courseId): ?array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function enroll(int $courseId, array $studentIds, array $options = []): array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function unenroll(int $courseId, array $studentIds): array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function progress(int $courseId, int $studentId): ?array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function isAvailable(): bool;
    public function unavailableReason(): ?string;
}
