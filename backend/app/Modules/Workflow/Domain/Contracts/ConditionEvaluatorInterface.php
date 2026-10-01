<?php

namespace App\Modules\Workflow\Domain\Contracts;

use App\Modules\Workflow\Domain\Support\WorkflowContext;

/**
 * Mengevaluasi ekspresi kondisi terhadap konteks request (§30).
 *
 * Implementasi default (`DefaultConditionEvaluator`) mendukung AND/OR/NOT
 * bersarang, operator pada `ConditionOperator`, dan perbandingan angka
 * yang toleran terhadap string ("15000000" > 1000000 tetap benar).
 */
interface ConditionEvaluatorInterface
{
    /**
     * @param  array<string, mixed>|null  $expression  ekspresi kondisi (pohon)
     */
    public function evaluate(?array $expression, WorkflowContext $context): bool;

    /** Nama operator yang didukung, mis. "BETWEEN", "IS_EMPTY". */
    public function supports(string $operator): bool;

    /** @return list<string> */
    public function operators(): array;

    /**
     * Validasi struktur ekspresi tanpa mengeksekusinya (dipakai saat publish).
     *
     * @return list<string> daftar pesan kesalahan; kosong berarti sah
     */
    public function validate(?array $expression): array;
}
