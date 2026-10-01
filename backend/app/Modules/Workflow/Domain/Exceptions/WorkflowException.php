<?php

namespace App\Modules\Workflow\Domain\Exceptions;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Dasar seluruh penolakan engine.
 *
 * Kelas turunan mengimplementasikan `Responsable` sehingga pesan domain dapat
 * langsung dipetakan ke HTTP tanpa setiap controller menulis blok try/catch —
 * dan tanpa pernah membocorkan detail internal ke klien.
 */
abstract class WorkflowException extends RuntimeException implements Responsable
{
    abstract public function statusCode(): int;

    /** @param array<string, mixed> $context */
    public function __construct(
        string $message,
        protected array $context = [],
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }

    public function toResponse($request): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $this->getMessage(),
        ];

        if ($this->context !== []) {
            $payload['errors'] = $this->context;
        }

        return response()->json($payload, $this->statusCode());
    }
}
