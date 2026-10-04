<?php

namespace App\Modules\Procurement\Domain\Exceptions;

use RuntimeException;

/**
 * Pelanggaran aturan bisnis pengadaan.
 *
 * Dilempar oleh service dan diterjemahkan controller menjadi `errorResponse`
 * berstatus 422 dengan pesan berbahasa Indonesia (§48).
 */
class ProcurementException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $errorCode = null)
    {
        parent::__construct($message);
    }

    public static function because(string $message, ?string $errorCode = null): self
    {
        return new self($message, $errorCode);
    }
}
