<?php

namespace App\Modules\Activity\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Bentrok keadaan: kursi sudah penuh, jadwal beririsan, anggota masih aktif.
 *
 * Dipisahkan dari galat validasi karena maknanya berbeda: permintaannya sah,
 * tetapi **keadaan saat ini** membuatnya tidak dapat dijalankan — dan pemanggil
 * biasanya dapat mencoba lagi (masuk daftar tunggu, memilih jam lain).
 */
class ActivityConflictException extends RuntimeException
{
    public function __construct(string $message, protected array $context = [])
    {
        parent::__construct($message);
    }

    public static function make(string $message, array $context = []): self
    {
        return new self($message, $context);
    }

    public function context(): array
    {
        return $this->context;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'conflict' => $this->context,
        ], 409);
    }
}
