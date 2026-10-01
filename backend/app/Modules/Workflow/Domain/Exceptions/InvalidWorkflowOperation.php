<?php

namespace App\Modules\Workflow\Domain\Exceptions;

/**
 * Operasi tidak sah menurut kebijakan workflow (HTTP 422).
 *
 * Contoh: self-approval dilarang, komentar wajib kosong, request belum berstatus
 * RETURNED sehingga tidak bisa direvisi, delegasi dengan rentang waktu terbalik.
 */
class InvalidWorkflowOperation extends WorkflowException
{
    public function statusCode(): int
    {
        return 422;
    }

    /** @param array<string, mixed> $context */
    public static function make(string $message, array $context = []): self
    {
        return new self($message, $context);
    }
}
