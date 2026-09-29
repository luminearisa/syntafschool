<?php

namespace App\Modules\LMS\Application\Validation;

use App\Modules\LMS\Domain\Enums\ValidationSeverity;

/**
 * Hasil validasi berlapis (§17).
 *
 * Satu laporan dikumpulkan dari seluruh validator sehingga UI dapat
 * menampilkan semuanya sekaligus: guru tidak perlu memperbaiki satu per satu
 * sambil menekan tombol Publish berulang kali.
 */
final class ValidationReport
{
    /** @var list<ValidationIssue> */
    private array $issues = [];

    /** @param list<ValidationIssue> $issues */
    public function __construct(array $issues = [])
    {
        foreach ($issues as $issue) {
            $this->add($issue);
        }
    }

    public function add(ValidationIssue $issue): self
    {
        $this->issues[] = $issue;

        return $this;
    }

    /** @param list<ValidationIssue> $issues */
    public function addMany(array $issues): self
    {
        foreach ($issues as $issue) {
            $this->add($issue);
        }

        return $this;
    }

    public function merge(self $other): self
    {
        return $this->addMany($other->issues());
    }

    public function error(string $code, string $message, string $scope = 'course', ?int $entityId = null, ?string $fix = null, array $context = []): self
    {
        return $this->add(new ValidationIssue($code, $message, ValidationSeverity::ERROR, $scope, $entityId, null, $context, $fix));
    }

    public function warning(string $code, string $message, string $scope = 'course', ?int $entityId = null, ?string $fix = null, array $context = []): self
    {
        return $this->add(new ValidationIssue($code, $message, ValidationSeverity::WARNING, $scope, $entityId, null, $context, $fix));
    }

    public function info(string $code, string $message, string $scope = 'course', ?int $entityId = null, ?string $fix = null, array $context = []): self
    {
        return $this->add(new ValidationIssue($code, $message, ValidationSeverity::INFO, $scope, $entityId, null, $context, $fix));
    }

    /** @return list<ValidationIssue> */
    public function issues(): array
    {
        return $this->issues;
    }

    /** @return list<ValidationIssue> */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, fn (ValidationIssue $issue) => $issue->severity === ValidationSeverity::ERROR));
    }

    /** @return list<ValidationIssue> */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, fn (ValidationIssue $issue) => $issue->severity === ValidationSeverity::WARNING));
    }

    public function hasErrors(): bool
    {
        return $this->errors() !== [];
    }

    /** Boleh diterbitkan? Hanya temuan ERROR yang menahan. */
    public function isPublishable(): bool
    {
        return ! $this->hasErrors();
    }

    public function count(): int
    {
        return count($this->issues);
    }

    public function countBySeverity(): array
    {
        $counts = [];

        foreach (ValidationSeverity::cases() as $severity) {
            $counts[$severity->value] = count(array_filter(
                $this->issues,
                fn (ValidationIssue $issue) => $issue->severity === $severity,
            ));
        }

        return $counts;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'publishable' => $this->isPublishable(),
            'has_errors' => $this->hasErrors(),
            'total' => $this->count(),
            'counts' => $this->countBySeverity(),
            'issues' => array_map(fn (ValidationIssue $issue) => $issue->toArray(), $this->issues),
        ];
    }
}
