<?php

namespace App\Modules\Hrd\Domain\Exceptions;

/**
 * Pemanggil tidak berhak atas baris/kolom yang diminta (§52, §79).
 *
 * Sengaja dibedakan dari "tidak ditemukan": layar tidak perlu memberi tahu
 * pemanggil bahwa data itu ada tetapi bukan haknya.
 */
class HrdAccessDeniedException extends HrdException
{
    protected string $ruleCode = 'hr.access.denied';

    public function statusCode(): int
    {
        return 403;
    }

    public static function forScope(string $scope, ?string $resource = null): self
    {
        $message = $resource === null
            ? "Anda tidak memiliki cakupan akses {$scope} untuk data ini."
            : "Anda tidak memiliki cakupan akses {$scope} untuk {$resource}.";

        return new self($message);
    }

    public static function forField(string $field): self
    {
        return new self("Kolom {$field} hanya dapat dibaca pemegang izin data sensitif.");
    }
}
