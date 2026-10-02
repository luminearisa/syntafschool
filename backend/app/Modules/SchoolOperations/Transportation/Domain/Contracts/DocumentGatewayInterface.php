<?php

namespace App\Modules\SchoolOperations\Transportation\Domain\Contracts;

interface DocumentGatewayInterface
{
    public function store(string $path, array $meta = []): array;

    public function url(string $path): ?string;

    public function available(): bool;
}
