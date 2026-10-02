<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * TeacherProviderInterface
 *
 * Tanda tangan kontrak ini SENGAJA dicerminkan dari:
 *   App\Modules\Activity\Domain\Contracts\TeacherProviderInterface
 *
 * Dengan begitu delegasi menjadi passthrough tanpa lapisan terjemahan — tidak
 * ada nama metode yang perlu ditebak, yang merupakan sumber kegagalan senyap
 * paling mudah lolos ke produksi.
 *
 * Modul Event hanya melakukan orkestrasi; data pokok tetap milik modul asalnya.
 * Bila integrasi belum terpasang, penyedia wajib menyatakan
 * `isAvailable() === false` beserta alasannya, bukan mengarang data.
 */
interface TeacherProviderInterface
{
    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function profile(int $employeeId): ?array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function userIdsForEmployee(int $employeeId): array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function search(string $term, int $limit = 20): array;
    public function isAvailable(): bool;

    public function unavailableReason(): ?string;
}
