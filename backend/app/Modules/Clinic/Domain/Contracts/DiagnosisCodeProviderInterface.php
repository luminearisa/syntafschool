<?php

namespace App\Modules\Clinic\Domain\Contracts;

/**
 * Sumber kode diagnosis. Implementasi bawaan memakai master internal
 * (`clinic_diagnoses_master`); instalasi yang ingin memakai ICD-10/ICD-11
 * cukup mengganti binding kontrak ini tanpa mengubah layar klinis.
 */
interface DiagnosisCodeProviderInterface
{
    public function systemName(): string;

    /**
     * @return list<array{code: string, name: string, category: ?string}>
     */
    public function search(string $term, int $limit = 20): array;

    /** @return array{code: string, name: string, category: ?string}|null */
    public function find(string $code): ?array;

    public function isAvailable(): bool;
}
