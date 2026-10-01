<?php

namespace App\Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kondisi bernama yang dapat dirujuk node/edge lewat `condition_code` (§30).
 *
 * Menyimpan kondisi sebagai baris membuat admin dapat memakai ulang satu aturan
 * (mis. "nominal di atas 10 juta") di beberapa cabang dan mengubahnya di satu
 * tempat, sementara edge tetap menentukan arah alurnya.
 */
class WorkflowCondition extends WorkflowModel
{
    protected $table = 'workflow_conditions';

    protected $casts = [
        'expression' => 'array',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'version_id');
    }

    /** @return array<string, mixed> ekspresi kondisi siap dievaluasi */
    public function toExpression(): array
    {
        $expression = $this->expression ?? [];

        if ($expression === []) {
            return [];
        }

        // Bila sudah berbentuk pohon (all/any/not/clauses) langsung dipakai.
        if (array_intersect(array_keys($expression), ['all', 'any', 'not', 'clauses', 'logic']) !== []) {
            return $expression;
        }

        // Bentuk ringkas: daftar klausa dengan `logic` sebagai penghubung.
        return [
            'logic' => strtoupper((string) $this->logic),
            'clauses' => $expression,
        ];
    }
}
