<?php

namespace App\Modules\Asset\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Pelanggaran aturan siklus hidup (transisi status terlarang, benturan jadwal,
 * konflik peminjaman). Dibedakan dari 422 validasi form karena sebabnya ada di
 * keadaan data, bukan di bentuk masukan — dan itu penting saat diperiksa.
 */
class AssetDomainException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 409, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'asset_domain',
        ], $this->status);
    }
}
