<?php

namespace App\Modules\SchoolOperations\Laundry\Domain\Exceptions;

use RuntimeException;

/** Aturan bisnis laundry dilanggar (transisi status, jatah, pembayaran). */
class LaundryDomainException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
