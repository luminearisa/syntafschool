<?php

namespace App\Modules\Hrd\Domain\Exceptions;

use RuntimeException;

/**
 * Akar seluruh pengecualian domain HRD.
 *
 * Pengecualian domain dipisahkan dari `ValidationException` HTTP agar aturan
 * bisnis tetap dapat diuji dan dipakai dari perintah artisan, penjadwal, maupun
 * listener antrean — bukan hanya dari controller.
 */
class HrdException extends RuntimeException
{
    /** Kode aturan yang dilanggar, mis. `hr.assignment.multiple_primary`. */
    protected string $ruleCode = 'hr.error';

    public function ruleCode(): string
    {
        return $this->ruleCode;
    }

    /** Status HTTP yang pantas bila pengecualian ini sampai ke lapisan HTTP. */
    public function statusCode(): int
    {
        return 422;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return [];
    }
}
