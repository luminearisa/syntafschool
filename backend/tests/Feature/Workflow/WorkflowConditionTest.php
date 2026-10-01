<?php

namespace Tests\Feature\Workflow;

use App\Modules\Workflow\Application\Services\ConditionEvaluatorService;
use App\Modules\Workflow\Domain\Support\WorkflowContext;

/**
 * §30 — Condition Engine: operator, tipe data, dan pohon AND/OR/NOT.
 */
class WorkflowConditionTest extends WorkflowTestCase
{
    protected function evaluator(): ConditionEvaluatorService
    {
        return app(ConditionEvaluatorService::class);
    }

    protected function context(array $data = []): WorkflowContext
    {
        return WorkflowContext::fromArray(array_merge([
            'amount' => 15000000,
            'department' => 'FINANCE',
            'cost_center_id' => 10,
            'priority' => 'HIGH',
            'notes' => '',
            'tags' => ['a', 'b'],
        ], $data));
    }

    public function test_operator_numerik_dan_toleransi_tipe_string(): void
    {
        $service = $this->evaluator();

        $this->assertTrue($service->evaluate(['field' => 'amount', 'operator' => '>=', 'value' => 10000000], $this->context()));
        $this->assertFalse($service->evaluate(['field' => 'amount', 'operator' => '<', 'value' => 1000000], $this->context()));

        // Nilai dari basis data sering datang sebagai string.
        $this->assertTrue($service->evaluate(
            ['field' => 'amount', 'operator' => '>', 'value' => 1000000],
            WorkflowContext::fromArray(['amount' => '15000000.00']),
        ));
    }

    public function test_operator_daftar_rentang_dan_teks(): void
    {
        $service = $this->evaluator();

        $this->assertTrue($service->evaluate(['field' => 'department', 'operator' => 'IN', 'value' => ['FINANCE', 'HRD']], $this->context()));
        $this->assertTrue($service->evaluate(['field' => 'department', 'operator' => 'NOT_IN', 'value' => ['HRD']], $this->context()));
        $this->assertTrue($service->evaluate(['field' => 'amount', 'operator' => 'BETWEEN', 'value' => [1000000, 20000000]], $this->context()));
        $this->assertFalse($service->evaluate(['field' => 'amount', 'operator' => 'BETWEEN', 'value' => [1, 10]], $this->context()));
        $this->assertTrue($service->evaluate(['field' => 'priority', 'operator' => 'CONTAINS', 'value' => 'HIGH'], $this->context()));
        $this->assertTrue($service->evaluate(['field' => 'notes', 'operator' => 'IS_EMPTY'], $this->context()));
        $this->assertTrue($service->evaluate(['field' => 'amount', 'operator' => 'IS_NOT_EMPTY'], $this->context()));
    }

    public function test_pohon_and_or_not_bersarang(): void
    {
        $service = $this->evaluator();

        $expression = [
            'all' => [
                ['field' => 'amount', 'operator' => '>', 'value' => 1000000],
                [
                    'any' => [
                        ['field' => 'department', 'operator' => '=', 'value' => 'HRD'],
                        ['field' => 'priority', 'operator' => '=', 'value' => 'HIGH'],
                    ],
                ],
                [
                    'not' => ['field' => 'category', 'operator' => '=', 'value' => 'blocked'],
                ],
            ],
        ];

        $this->assertTrue($service->evaluate($expression, $this->context()));

        $this->assertFalse($service->evaluate($expression, $this->context([
            'category' => 'blocked',
        ])));

        $this->assertTrue($service->evaluate([
            'logic' => 'OR',
            'clauses' => [
                ['field' => 'amount', 'operator' => '>', 'value' => 99999999],
                ['field' => 'department', 'operator' => '=', 'value' => 'FINANCE'],
            ],
        ], $this->context()));
    }

    public function test_bentuk_ringkas_dan_operator_tak_dikenal_dianggap_tidak_terpenuhi(): void
    {
        $service = $this->evaluator();

        $this->assertTrue($service->evaluate(['amount' => ['>=' => 10000000]], $this->context()));
        $this->assertFalse($service->evaluate(['field' => 'amount', 'operator' => 'REGEX', 'value' => '.*'], $this->context()));
    }

    public function test_validasi_menolak_ekspresi_cacat(): void
    {
        $errors = $this->evaluator()->evaluator()->validate([
            'all' => [
                ['field' => 'amount', 'operator' => 'BETWEEN', 'value' => [1]],
                ['operator' => '>', 'value' => 5],
            ],
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('BETWEEN', implode(' ', $errors));
        $this->assertStringContainsString('field', implode(' ', $errors));
    }

    public function test_kondisi_kosong_dianggap_selalu_benar(): void
    {
        $this->assertTrue($this->evaluator()->evaluate(null, $this->context()));
        $this->assertTrue($this->evaluator()->evaluate([], $this->context()));
    }
}
