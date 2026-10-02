<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * EmployeeProviderInterface
 *
 * Tanda tangan kontrak ini SENGAJA dicerminkan dari:
 *   App\Modules\Hrd\Domain\Contracts\EmployeeProviderInterface
 *
 * Dengan begitu delegasi menjadi passthrough tanpa lapisan terjemahan — tidak
 * ada nama metode yang perlu ditebak, yang merupakan sumber kegagalan senyap
 * paling mudah lolos ke produksi.
 *
 * Modul Event hanya melakukan orkestrasi; data pokok tetap milik modul asalnya.
 * Bila integrasi belum terpasang, penyedia wajib menyatakan
 * `isAvailable() === false` beserta alasannya, bukan mengarang data.
 */
interface EmployeeProviderInterface
{
    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function findById(int $employeeId): ?array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function findByNumber(string $employeeNumber): ?array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function findByUserId(int $userId): ?array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function summary(int $employeeId): ?array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function isActive(int $employeeId, ?string $onDate = null): bool;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function activeInOrganization(int $organizationId, bool $includeDescendants = false): array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function directReportsOf(int $employeeId): array;
    public function isAvailable(): bool;

    public function unavailableReason(): ?string;
}
