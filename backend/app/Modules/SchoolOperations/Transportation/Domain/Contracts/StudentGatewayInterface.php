<?php

namespace App\Modules\SchoolOperations\Transportation\Domain\Contracts;

/** Data siswa (sumber kebenaran: Academic/Student). Tidak boleh diubah dari sini. */
interface StudentGatewayInterface
{
    public function snapshot(int $studentId): ?array;

    public function snapshots(array $studentIds): array;

    public function search(string $term, int $limit = 20): array;

    public function isActive(int $studentId): bool;

    public function available(): bool;

    public function unavailableReason(): ?string;
}
