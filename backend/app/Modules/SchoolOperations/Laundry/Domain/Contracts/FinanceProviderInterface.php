<?php

namespace App\Modules\SchoolOperations\Laundry\Domain\Contracts;

/**
 * Keuangan (§24). Finance adalah source of truth akuntansi — laundry hanya
 * mengirim posting pendapatan/refund. Bila modul keuangan belum terpasang,
 * hasil dikembalikan PENDING agar dapat diposting kemudian.
 */
interface FinanceProviderInterface
{
    /** @param array{amount:float, reference:string, description?:string, occurred_at?:string, metadata?:array} $payload */
    public function postRevenue(array $payload): array;

    /** @param array{amount:float, reference:string, description?:string, occurred_at?:string, metadata?:array} $payload */
    public function postRefund(array $payload): array;

    public function available(): bool;
}
