<?php

namespace App\Modules\LMS\Application\Validation;

use App\Modules\LMS\Domain\Enums\ValidationSeverity;

/**
 * Satu temuan validator (§17).
 *
 * `fix` sengaja disertakan supaya guru menerima saran tindakan, bukan sekadar
 * daftar kesalahan — dan `blocksPublication()` yang menentukan apakah temuan
 * ini menahan penerbitan.
 */
final readonly class ValidationIssue
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $code,
        public string $message,
        public ValidationSeverity $severity = ValidationSeverity::ERROR,
        public string $scope = 'course',
        public ?int $entityId = null,
        public ?string $entityLabel = null,
        public array $context = [],
        public ?string $fix = null,
    ) {}

    public function blocksPublication(): bool
    {
        return $this->severity->blocksPublication();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'severity' => $this->severity->value,
            'severity_label' => $this->severity->label(),
            'scope' => $this->scope,
            'entity_id' => $this->entityId,
            'entity_label' => $this->entityLabel,
            'context' => $this->context,
            'fix' => $this->fix,
            'blocks_publication' => $this->blocksPublication(),
        ];
    }
}
