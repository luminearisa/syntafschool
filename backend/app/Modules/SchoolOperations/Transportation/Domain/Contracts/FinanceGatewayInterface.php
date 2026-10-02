<?php

namespace App\Modules\SchoolOperations\Transportation\Domain\Contracts;

/** Keuangan: biaya operasional & penagihan layanan transportasi (§60/§61). */
interface FinanceGatewayInterface
{
    public function postCost(array $payload): array;

    public function postingEnabled(): bool;

    public function available(): bool;
}
