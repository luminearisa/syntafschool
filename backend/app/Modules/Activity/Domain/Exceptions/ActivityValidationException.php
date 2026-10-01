<?php

namespace App\Modules\Activity\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Aturan bisnis dilanggar (permintaan tidak memenuhi syarat).
 *
 * Selalu 422 dengan daftar pelanggaran yang dapat dibaca — bukan "gagal"
 * tanpa penjelasan, karena pendaftaran aktivitas sering dilakukan siswa dan
 * orang tua yang perlu tahu apa yang harus diperbaiki.
 */
class ActivityValidationException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(string $message, protected array $errors = [], protected int $status = 422)
    {
        parent::__construct($message);
    }

    /** @param list<string> $errors */
    public static function withErrors(array $errors, string $message = 'Permintaan tidak memenuhi syarat.'): self
    {
        return new self($message, $errors);
    }

    /** @param list<string> $errors */
    public static function forbidden(string $message, array $errors = []): self
    {
        return new self($message, $errors, 403);
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => ['activity' => $this->errors],
        ], $this->status);
    }

    public function report(): void
    {
        Log::info('ActivityValidationException', [
            'message' => $this->getMessage(),
            'errors' => $this->errors,
        ]);
    }
}
