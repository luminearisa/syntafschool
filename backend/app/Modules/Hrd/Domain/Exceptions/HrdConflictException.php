<?php

namespace App\Modules\Hrd\Domain\Exceptions;

/**
 * Benturan konkurensi & permintaan ganda (§78).
 *
 * Dua kasus nyata yang harus menjawab jelas, bukan diam-diam menimpa:
 *  - dua HR menyunting pegawai yang sama (kunci optimistis `version`);
 *  - permintaan yang sama dikirim ulang karena jaringan putus (`idempotency_key`).
 */
class HrdConflictException extends HrdException
{
    protected string $ruleCode = 'hr.conflict';

    public function statusCode(): int
    {
        return 409;
    }

    public static function staleVersion(string $entity, int $expected, int $actual): self
    {
        return new self(
            "Data {$entity} telah berubah sejak Anda membukanya (versi {$expected} → {$actual}). Muat ulang lalu ulangi perubahan Anda."
        );
    }

    public static function duplicateRequest(string $key): self
    {
        return new self("Permintaan dengan kunci idempotensi {$key} sudah pernah diproses.");
    }

    public static function locked(string $reason): self
    {
        return new self($reason);
    }
}
