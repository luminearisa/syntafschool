<?php

namespace App\Modules\SchoolOperations\Transportation\Domain\Contracts;

/** Workflow Engine yang sudah ada dipakai untuk persetujuan (§26). */
interface WorkflowGatewayInterface
{
    public function start(string $definition, array $payload): array;

    public function status(string $reference): ?array;

    public function approve(string $reference, array $payload = []): array;

    public function available(): bool;
}
