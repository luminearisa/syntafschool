<?php

namespace App\Modules\Bk\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\MessageBag;
use RuntimeException;

/**
 * Pelanggaran aturan bisnis BK (bukan kesalahan teknis).
 *
 * Diterjemahkan menjadi HTTP 422 dengan bentuk yang sama seperti kegagalan
 * validasi Laravel, sehingga frontend cukup menampilkan pesannya per kolom.
 */
class BkValidationException extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(private readonly array $errors, string $message = 'Data yang dikirim belum memenuhi aturan modul BK.')
    {
        parent::__construct($message);
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_values(array_merge(...array_values($this->errors())));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => $this->errors(),
        ], 422);
    }
}
