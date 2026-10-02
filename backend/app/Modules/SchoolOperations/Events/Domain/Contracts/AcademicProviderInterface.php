<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * AcademicProviderInterface
 *
 * Tanda tangan kontrak ini SENGAJA dicerminkan dari:
 *   App\Modules\Activity\Domain\Contracts\AcademicProviderInterface
 *
 * Dengan begitu delegasi menjadi passthrough tanpa lapisan terjemahan — tidak
 * ada nama metode yang perlu ditebak, yang merupakan sumber kegagalan senyap
 * paling mudah lolos ke produksi.
 *
 * Modul Event hanya melakukan orkestrasi; data pokok tetap milik modul asalnya.
 * Bila integrasi belum terpasang, penyedia wajib menyatakan
 * `isAvailable() === false` beserta alasannya, bukan mengarang data.
 */
interface AcademicProviderInterface
{
    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function context(): array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function classLevel(int $studentId): ?int;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function programName(int $studentId): ?string;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function standing(int $studentId): array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function submitRecognition(int $studentId, array $payload): array;

    /** Diteruskan apa adanya ke kontrak modul asalnya. */
    public function reportCardSummary(int $studentId, ?int $periodId = null): array;
    public function isAvailable(): bool;

    public function unavailableReason(): ?string;
}
