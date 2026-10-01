<?php

namespace App\Modules\Asset\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Permintaan yang tidak memenuhi aturan modul (HTTP 422).
 *
 * Pesannya membawa `errors` bergaya validasi agar dapat ditampilkan di form
 * mana pun tanpa penanganan khusus, tetapi juga dapat dilempar dari layanan
 * (bukan hanya dari Form Request) — mis. "aset yang dipinjam sedang diperbaiki".
 */
class AssetValidationException extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(private readonly array $errors, string $message = 'Data aset tidak memenuhi aturan.')
    {
        parent::__construct($message);
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => $this->errors,
        ], 422);
    }
}
