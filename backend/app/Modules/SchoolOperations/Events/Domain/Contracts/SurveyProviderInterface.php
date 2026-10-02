<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Domain\Contracts;

/**
 * SurveyProviderInterface
 *
 * Survei pasca event (§76).
 *
 * Modul Event hanya melakukan orkestrasi: seluruh data pokok tetap dimiliki
 * modul asalnya. Bila sumber belum terpasang, implementasi wajib menyatakan
 * `available() === false` beserta alasannya — bukan mengarang data.
 */
interface SurveyProviderInterface
{
    /** Membuat survei. */
    public function create(array $payload): array;

    /** Hasil survei. */
    public function results(int $surveyId): array;

    /** Apakah modul survei tersedia. */
    public function isAvailable(): bool;

    /** Alasan bila tidak tersedia. */
    public function unavailableReason(): ?string;
}
