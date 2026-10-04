<?php

namespace App\Modules\Procurement\Application\Services;

use App\Modules\Procurement\Domain\Enums\EvaluationStatus;
use App\Modules\Procurement\Domain\Enums\ProcurementAuditAction;
use App\Modules\Procurement\Models\EvaluationCriteria;
use App\Modules\Procurement\Models\Quotation;
use App\Modules\Procurement\Models\QuotationEvaluation;
use Illuminate\Support\Facades\DB;

/**
 * Evaluasi penawaran (§15).
 *
 * Bobot kriteria **configurable**: bila RFQ belum punya kriteria, dipakai bobot
 * bawaan dari `config('procurement.evaluation_weights')`.
 */
class QuotationEvaluationService
{
    public function __construct(private readonly ProcurementAuditService $audit)
    {
    }

    /** Kriteria evaluasi efektif untuk sebuah RFQ. */
    public function criteria(?int $rfqId): array
    {
        if ($rfqId !== null) {
            $rows = EvaluationCriteria::query()->where('rfq_id', $rfqId)->where('is_active', true)->get();

            if ($rows->isNotEmpty()) {
                return $rows->map(fn (EvaluationCriteria $c): array => [
                    'id' => $c->id,
                    'code' => $c->code,
                    'name' => $c->name,
                    'weight' => (float) $c->weight,
                    'max_score' => (float) $c->max_score,
                    'type' => $c->type,
                ])->all();
            }
        }

        $weights = (array) config('procurement.evaluation_weights', []);

        return array_map(fn (string $code, $weight): array => [
            'id' => null,
            'code' => strtoupper($code),
            'name' => ucfirst($code),
            'weight' => (float) $weight,
            'max_score' => 100.0,
            'type' => 'NUMERIC',
        ], array_keys($weights), array_values($weights));
    }

    /** @param array<string, mixed> $data */
    public function defineCriteria(int $rfqId, array $criteria): void
    {
        foreach ($criteria as $row) {
            EvaluationCriteria::query()->updateOrCreate(
                ['rfq_id' => $rfqId, 'code' => $row['code']],
                [
                    'name' => $row['name'],
                    'weight' => $row['weight'] ?? 0,
                    'max_score' => $row['max_score'] ?? 100,
                    'type' => $row['type'] ?? 'NUMERIC',
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * Simpan hasil evaluasi satu penawaran.
     *
     * @param array<string, mixed> $data  {evaluator_id, comments, recommendation, scores: [{code, score, comment, evidence_path}]}
     */
    public function evaluate(Quotation $quotation, array $data): QuotationEvaluation
    {
        return DB::transaction(function () use ($quotation, $data): QuotationEvaluation {
            $criteria = collect($this->criteria($quotation->rfq_id))->keyBy('code');

            $evaluation = QuotationEvaluation::query()->create([
                'quotation_id' => $quotation->id,
                'rfq_id' => $quotation->rfq_id,
                'evaluator_id' => $data['evaluator_id'] ?? \Illuminate\Support\Facades\Auth::id(),
                'status' => EvaluationStatus::COMPLETED->value,
                'comments' => $data['comments'] ?? null,
                'recommendation' => $data['recommendation'] ?? null,
                'evaluated_at' => now(),
            ]);

            $totalScore = 0.0;
            $weighted = 0.0;

            foreach ((array) ($data['scores'] ?? []) as $row) {
                $code = (string) ($row['code'] ?? '');
                $criterion = $criteria->get($code);

                $weight = (float) ($criterion['weight'] ?? ($row['weight'] ?? 0));
                $maxScore = (float) ($criterion['max_score'] ?? ($row['max_score'] ?? 100));
                $score = (float) ($row['score'] ?? 0);

                $totalScore += $score;
                $weighted += $maxScore > 0 ? ($score / $maxScore) * $weight : 0;

                $evaluation->items()->create([
                    'criteria_id' => $criterion['id'] ?? null,
                    'criterion_code' => $code,
                    'criterion_name' => $criterion['name'] ?? ($row['name'] ?? $code),
                    'weight' => $weight,
                    'score' => $score,
                    'max_score' => $maxScore,
                    'result' => $row['result'] ?? null,
                    'comment' => $row['comment'] ?? null,
                    'evidence_path' => $row['evidence_path'] ?? null,
                ]);
            }

            $evaluation->forceFill([
                'total_score' => round($totalScore, 2),
                'weighted_score' => round($weighted, 2),
            ])->save();

            $quotation->forceFill(['status' => \App\Modules\Procurement\Domain\Enums\QuotationStatus::EVALUATED->value])->save();

            $this->audit->record(ProcurementAuditAction::UPDATE, $evaluation, null, $evaluation->toArray(), 'Evaluasi penawaran');

            return $evaluation->fresh('items');
        });
    }

    /** Peringkat penawaran berdasarkan skor tertimbang (informasi, bukan keputusan). */
    public function ranking(int $rfqId): array
    {
        return QuotationEvaluation::query()
            ->where('rfq_id', $rfqId)
            ->with('quotation.vendor:id,legal_name')
            ->orderByDesc('weighted_score')
            ->get()
            ->map(fn (QuotationEvaluation $e): array => [
                'quotation_id' => $e->quotation_id,
                'vendor' => $e->quotation?->vendor?->legal_name,
                'weighted_score' => (float) $e->weighted_score,
                'recommendation' => $e->recommendation,
            ])
            ->all();
    }
}
