<?php

namespace Tests\Feature\Workflow;

use App\Modules\Workflow\Application\Services\WorkflowBuilderService;
use App\Modules\Workflow\Domain\Exceptions\InvalidWorkflowOperation;
use App\Modules\Workflow\Models\WorkflowDefinition;

/**
 * §13–§15, §54–§55 — definisi, versi, builder, publikasi.
 */
class WorkflowDefinitionTest extends WorkflowTestCase
{
    public function test_definisi_tanpa_approver_tidak_dapat_diterbitkan(): void
    {
        $graph = $this->linearGraph([
            ['key' => 'a', 'name' => 'Tanpa Approver', 'approvers' => []],
        ]);

        $definition = $this->makeWorkflow($graph['nodes'], $graph['edges'], 'NO_APPROVER', publish: false);

        $builder = app(WorkflowBuilderService::class);
        $version = $this->currentDraft($definition);

        $report = $builder->validate($version);

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString('resolver approver', implode(' ', $report['errors']));

        $this->expectException(InvalidWorkflowOperation::class);

        $builder->publish($version, $this->makeUser('WORKFLOW_ADMIN'));
    }

    public function test_graf_terbit_menjadi_beku_dan_request_lama_tetap_pada_versinya(): void
    {
        $graph = $this->linearGraph([
            ['key' => 'a', 'name' => 'Supervisor', 'approvers' => [['resolver' => 'user', 'user_id' => $this->makeUser('bendahara')->id]]],
        ]);

        $definition = $this->makeWorkflow($graph['nodes'], $graph['edges'], 'FREEZE_TEST');
        $v1 = $this->currentVersion($definition);

        $this->assertSame('ACTIVE', $v1->status->value);
        $this->assertNotNull($v1->compiled_graph);
        $this->assertNotNull($v1->graph_hash);
        $this->assertSame('ACTIVE', $definition->status->value);

        // Versi terbit tidak dapat diubah lagi.
        $this->expectException(InvalidWorkflowOperation::class);

        app(WorkflowBuilderService::class)->saveGraph($v1, $graph['nodes'], $graph['edges']);
    }

    public function test_versi_baru_menggantikan_yang_aktif_tanpa_mengubah_versi_lama(): void
    {
        $graph = $this->linearGraph([
            ['key' => 'a', 'name' => 'Supervisor', 'approvers' => [['resolver' => 'user', 'user_id' => $this->makeUser('bendahara')->id]]],
        ]);

        $definition = $this->makeWorkflow($graph['nodes'], $graph['edges'], 'VERSION_TEST');
        $v1 = $this->currentVersion($definition);

        $builder = app(WorkflowBuilderService::class);
        $actor = $this->makeUser('WORKFLOW_ADMIN');

        $v2 = $builder->createVersion($definition, $actor, $v1, 'v2', false);
        $builder->publish($v2, $actor, 'perbaikan SLA');

        $this->assertSame('INACTIVE', $v1->refresh()->status->value);
        $this->assertSame('ACTIVE', $v2->refresh()->status->value);
        $this->assertSame($v2->id, $definition->refresh()->current_version_id);
        $this->assertNotNull($v1->compiled_graph, 'Graf versi lama tetap tersimpan untuk request yang sedang berjalan.');
    }

    public function test_validator_menolak_siklus_node_yatim_dan_cabang_paralel_tanpa_temu(): void
    {
        $builder = app(WorkflowBuilderService::class);
        $actor = $this->makeUser('WORKFLOW_ADMIN');
        $approver = $this->makeUser('bendahara');

        $definition = app(\App\Modules\Workflow\Application\Services\WorkflowDefinitionService::class)
            ->create(['code' => 'INVALID_GRAPH', 'name' => 'Graf Tidak Sah', 'module' => 'testing'], $actor);

        $version = $builder->createVersion($definition, $actor, null, 'v1', false);

        $builder->saveGraph($version, [
            ['key' => 'start', 'type' => 'START', 'name' => 'Mulai', 'sequence' => 1],
            ['key' => 'a', 'type' => 'APPROVAL', 'name' => 'A', 'sequence' => 2, 'approvers' => [['resolver' => 'user', 'user_id' => $approver->id]]],
            ['key' => 'b', 'type' => 'APPROVAL', 'name' => 'B', 'sequence' => 3, 'approvers' => [['resolver' => 'user', 'user_id' => $approver->id]]],
            ['key' => 'orphan', 'type' => 'APPROVAL', 'name' => 'Yatim', 'sequence' => 4, 'approvers' => [['resolver' => 'user', 'user_id' => $approver->id]]],
            ['key' => 'end', 'type' => 'END', 'name' => 'Selesai', 'sequence' => 5],
        ], [
            ['from_key' => 'start', 'to_key' => 'a'],
            ['from_key' => 'a', 'to_key' => 'b'],
            ['from_key' => 'b', 'to_key' => 'a'],
            ['from_key' => 'b', 'to_key' => 'end'],
        ]);

        $report = $builder->validate($version->refresh());

        $this->assertFalse($report['valid']);
        $errors = implode(' | ', $report['errors']);

        $this->assertStringContainsString('siklus', $errors);
        $this->assertStringContainsString('orphan', $errors);
    }

    public function test_paralel_tanpa_titik_temu_ditolak(): void
    {
        $builder = app(WorkflowBuilderService::class);
        $actor = $this->makeUser('WORKFLOW_ADMIN');
        $approver = $this->makeUser('bendahara');

        $definition = app(\App\Modules\Workflow\Application\Services\WorkflowDefinitionService::class)
            ->create(['code' => 'PARALLEL_NO_MERGE', 'name' => 'Paralel Tanpa Temu', 'module' => 'testing'], $actor);

        $version = $builder->createVersion($definition, $actor, null, 'v1', false);

        $builder->saveGraph($version, [
            ['key' => 'start', 'type' => 'START', 'name' => 'Mulai', 'sequence' => 1],
            ['key' => 'fork', 'type' => 'PARALLEL', 'name' => 'Fork', 'sequence' => 2],
            ['key' => 'a', 'type' => 'APPROVAL', 'name' => 'A', 'sequence' => 3, 'approvers' => [['resolver' => 'user', 'user_id' => $approver->id]]],
            ['key' => 'b', 'type' => 'APPROVAL', 'name' => 'B', 'sequence' => 4, 'approvers' => [['resolver' => 'user', 'user_id' => $approver->id]]],
            ['key' => 'end_a', 'type' => 'END', 'name' => 'Selesai A', 'sequence' => 5],
            ['key' => 'end_b', 'type' => 'END', 'name' => 'Selesai B', 'sequence' => 6],
        ], [
            ['from_key' => 'start', 'to_key' => 'fork'],
            ['from_key' => 'fork', 'to_key' => 'a'],
            ['from_key' => 'fork', 'to_key' => 'b'],
            ['from_key' => 'a', 'to_key' => 'end_a'],
            ['from_key' => 'b', 'to_key' => 'end_b'],
        ]);

        $report = $builder->validate($version->refresh());

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString('bertemu kembali', implode(' ', $report['errors']));
    }

    public function test_definisi_bawaan_dapat_diterbitkan_dan_tampil_di_meta_builder(): void
    {
        $this->makeUser('WORKFLOW_ADMIN');

        $this->seed(\App\Modules\Workflow\Database\Seeders\WorkflowReferenceSeeder::class);

        $this->assertSame(4, WorkflowDefinition::query()->where('status', 'ACTIVE')->count());

        $meta = app(WorkflowBuilderService::class)->meta();

        $this->assertNotEmpty($meta['resolvers']);
        $this->assertNotEmpty($meta['node_types']);
        $this->assertContains('user', array_column($meta['resolvers'], 'alias'));
        $this->assertContains('subject.update_status', array_column($meta['actions'], 'alias'));
    }

    protected function currentDraft(WorkflowDefinition $definition): \App\Modules\Workflow\Models\WorkflowVersion
    {
        return $definition->versions()->orderByDesc('version')->firstOrFail();
    }
}
